<?php

namespace Tests\Feature\Audit;

use App\Models\AuditEvent;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use LogicException;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_log_records_the_actor_ip_action_and_json_metadata(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);
        $this->app->instance('request', Request::create(
            '/activity',
            'GET',
            server: [
                'REMOTE_ADDR' => '203.0.113.9',
                'HTTP_USER_AGENT' => 'NOC Test Agent',
            ],
        ));

        $event = $this->app->make(AuditLogger::class)->log('user.updated', $user, [
            'field' => 'notify_email',
            'from' => false,
            'to' => true,
        ]);

        $this->assertSame($user->id, $event->user_id);
        $this->assertSame('user.updated', $event->action);
        $this->assertSame('203.0.113.9', $event->ip);
        $this->assertSame('NOC Test Agent', $event->user_agent);
        $this->assertSame(User::class, $event->target_type);
        $this->assertSame((string) $user->id, $event->target_id);
        $this->assertSame([
            'field' => 'notify_email',
            'from' => false,
            'to' => true,
        ], $event->meta_json);

        $rawMeta = DB::table('audit_events')->where('id', $event->id)->value('meta_json');
        $this->assertSame(json_encode($event->meta_json), $rawMeta);
    }

    public function test_a_long_user_agent_and_remote_address_are_truncated_to_column_widths(): void
    {
        $this->app->instance('request', Request::create(
            '/activity',
            'GET',
            server: [
                'REMOTE_ADDR' => str_repeat('1', 200),
                'HTTP_USER_AGENT' => str_repeat('A', 5000),
            ],
        ));

        $event = $this->app->make(AuditLogger::class)->log('auth.login', null);

        $this->assertLessThanOrEqual(45, mb_strlen((string) $event->ip));
        $this->assertLessThanOrEqual(1024, mb_strlen((string) $event->user_agent));
        $this->assertSame(str_repeat('1', 45), $event->ip);
        $this->assertSame(str_repeat('A', 1024), $event->user_agent);

        $stored = AuditEvent::query()->findOrFail($event->id);
        $this->assertLessThanOrEqual(45, mb_strlen((string) $stored->ip));
        $this->assertLessThanOrEqual(1024, mb_strlen((string) $stored->user_agent));
    }

    public function test_an_unauthenticated_log_with_a_user_subject_stores_a_null_actor(): void
    {
        $subject = User::factory()->create();

        $event = $this->app->make(AuditLogger::class)->log('user.updated', $subject);

        $this->assertNull($event->user_id);
        $this->assertSame((string) $subject->id, $event->target_id);
    }

    public function test_an_explicit_actor_is_recorded_when_no_user_is_authenticated(): void
    {
        $actor = User::factory()->create();
        $subject = User::factory()->create();

        $event = $this->app->make(AuditLogger::class)->log('user.updated', $subject, [], $actor->id);

        $this->assertSame($actor->id, $event->user_id);
        $this->assertSame((string) $subject->id, $event->target_id);
    }

    public function test_secret_metadata_is_redacted_before_it_is_stored(): void
    {
        $user = User::factory()->create();

        $event = $this->app->make(AuditLogger::class)->log('settings.updated', $user, [
            'key' => 'alerts.channels.telegram.bot_token',
            'is_secret' => true,
            'bot_token' => '123456:super-secret',
            'nested' => ['password' => 'hunter2'],
        ]);

        $this->assertSame('[redacted]', $event->meta_json['bot_token']);
        $this->assertSame('[redacted]', $event->meta_json['nested']['password']);
        $this->assertSame('alerts.channels.telegram.bot_token', $event->meta_json['key']);
        $this->assertTrue($event->meta_json['is_secret']);

        $this->assertStringNotContainsString('123456:super-secret', (string) DB::table('audit_events')->where('id', $event->id)->value('meta_json'));
        $this->assertStringNotContainsString('hunter2', (string) DB::table('audit_events')->where('id', $event->id)->value('meta_json'));
    }

    public function test_secret_metadata_redacts_value_old_value_and_new_value(): void
    {
        $event = $this->app->make(AuditLogger::class)->log('settings.updated', null, [
            'key' => 'alerts.channels.telegram.bot_token',
            'is_secret' => true,
            'value' => 'plaintext-secret',
            'old_value' => 'old-plaintext-secret',
            'new_value' => 'new-plaintext-secret',
        ]);

        $this->assertSame('[redacted]', $event->meta_json['value']);
        $this->assertSame('[redacted]', $event->meta_json['old_value']);
        $this->assertSame('[redacted]', $event->meta_json['new_value']);
        $this->assertSame('alerts.channels.telegram.bot_token', $event->meta_json['key']);
        $this->assertTrue($event->meta_json['is_secret']);

        $stored = (string) DB::table('audit_events')->where('id', $event->id)->value('meta_json');
        $this->assertStringNotContainsString('plaintext-secret', $stored);
        $this->assertStringNotContainsString('old-plaintext-secret', $stored);
        $this->assertStringNotContainsString('new-plaintext-secret', $stored);
    }

    public function test_non_boolean_values_under_flag_prefixes_are_redacted(): void
    {
        $event = $this->app->make(AuditLogger::class)->log('settings.updated', null, [
            'is_secret' => 'true',
            'has_token' => 'abc123-secret',
            'is_enabled' => false,
        ]);

        $this->assertSame('[redacted]', $event->meta_json['is_secret']);
        $this->assertSame('[redacted]', $event->meta_json['has_token']);
        $this->assertFalse($event->meta_json['is_enabled']);

        $stored = (string) DB::table('audit_events')->where('id', $event->id)->value('meta_json');
        $this->assertStringNotContainsString('abc123-secret', $stored);
    }

    public function test_whitelisted_identifiers_survive_redaction_while_secrets_do_not(): void
    {
        $event = $this->app->make(AuditLogger::class)->log('token.rotated', null, [
            'server_id' => '01HX0000000000000000000001',
            'token_id' => '01HX0000000000000000000002',
            'token_prefix' => 'noc_abcd',
            'target_id' => '01HX0000000000000000000003',
            'actor_id' => '01HX0000000000000000000004',
            'user_id' => 7,
            'password' => 'hunter2',
            'secret' => 'super-secret',
            'query' => 'select * from users',
            'is_secret' => true,
            'value' => 'plaintext-value',
            'old_value' => 'old-plaintext-value',
            'new_value' => 'new-plaintext-value',
        ]);

        $this->assertSame('01HX0000000000000000000001', $event->meta_json['server_id']);
        $this->assertSame('01HX0000000000000000000002', $event->meta_json['token_id']);
        $this->assertSame('noc_abcd', $event->meta_json['token_prefix']);
        $this->assertSame('01HX0000000000000000000003', $event->meta_json['target_id']);
        $this->assertSame('01HX0000000000000000000004', $event->meta_json['actor_id']);
        $this->assertSame(7, $event->meta_json['user_id']);

        $this->assertSame('[redacted]', $event->meta_json['password']);
        $this->assertSame('[redacted]', $event->meta_json['secret']);
        $this->assertSame('[redacted]', $event->meta_json['query']);
        $this->assertSame('[redacted]', $event->meta_json['value']);
        $this->assertSame('[redacted]', $event->meta_json['old_value']);
        $this->assertSame('[redacted]', $event->meta_json['new_value']);
        $this->assertTrue($event->meta_json['is_secret']);

        $stored = (string) DB::table('audit_events')->where('id', $event->id)->value('meta_json');
        $this->assertStringNotContainsString('hunter2', $stored);
        $this->assertStringNotContainsString('super-secret', $stored);
        $this->assertStringNotContainsString('select * from users', $stored);
        $this->assertStringNotContainsString('plaintext-value', $stored);
    }

    public function test_audit_events_cannot_be_updated(): void
    {
        $event = $this->app->make(AuditLogger::class)->log('server.created');

        $this->expectException(LogicException::class);

        $event->update(['action' => 'server.deleted']);
    }

    public function test_audit_events_cannot_be_saved_after_creation(): void
    {
        $event = $this->app->make(AuditLogger::class)->log('server.created');

        $this->expectException(LogicException::class);

        $event->action = 'server.deleted';
        $event->save();
    }

    public function test_audit_events_cannot_be_deleted(): void
    {
        $event = $this->app->make(AuditLogger::class)->log('server.created');

        $this->expectException(LogicException::class);

        $event->delete();
    }

    public function test_audit_events_cannot_be_updated_through_the_query_builder(): void
    {
        $this->app->make(AuditLogger::class)->log('server.created');

        $this->expectException(LogicException::class);

        AuditEvent::query()->update(['action' => 'server.deleted']);
    }

    public function test_audit_events_cannot_be_deleted_through_the_query_builder(): void
    {
        $this->app->make(AuditLogger::class)->log('server.created');

        $this->expectException(LogicException::class);

        AuditEvent::query()->delete();
    }

    public function test_creating_audit_events_through_the_query_builder_still_works(): void
    {
        $event = AuditEvent::query()->create([
            'action' => 'server.created',
            'target_type' => User::class,
            'target_id' => '1',
        ]);

        $this->assertTrue($event->exists);
        $this->assertSame('server.created', $event->action);
    }

    public function test_a_login_event_updates_last_login_and_records_an_audit_event(): void
    {
        $user = User::factory()->create(['last_login_at' => null]);

        Event::dispatch(new Login('web', $user, false));

        $this->assertNotNull($user->fresh()->last_login_at);

        $audit = AuditEvent::query()->where('action', 'auth.login')->sole();

        $this->assertSame($user->id, $audit->user_id);
        $this->assertSame(User::class, $audit->target_type);
        $this->assertSame((string) $user->id, $audit->target_id);
    }

    public function test_a_logout_event_records_an_audit_event_for_the_actor(): void
    {
        $user = User::factory()->create();

        Event::dispatch(new Logout('web', $user));

        $audit = AuditEvent::query()->where('action', 'auth.logout')->sole();

        $this->assertSame($user->id, $audit->user_id);
        $this->assertSame(User::class, $audit->target_type);
        $this->assertSame((string) $user->id, $audit->target_id);
    }

    public function test_a_failed_login_event_records_a_redacted_email_without_the_password(): void
    {
        Event::dispatch(new Failed('web', null, [
            'email' => 'attacker@example.com',
            'password' => 'wrong-password',
        ]));

        $audit = AuditEvent::query()->where('action', 'auth.failed')->sole();

        $this->assertNull($audit->user_id);
        $this->assertNull($audit->target_id);
        $this->assertSame(['email' => '[redacted]'], $audit->meta_json);

        $stored = (string) DB::table('audit_events')->where('id', $audit->id)->value('meta_json');
        $this->assertStringNotContainsString('attacker@example.com', $stored);
        $this->assertStringNotContainsString('wrong-password', $stored);
    }
}
