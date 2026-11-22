<?php

namespace Tests\Feature\Audit;

use App\Models\AuditEvent;
use App\Models\User;
use App\Services\AuditLogger;
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
        $this->assertSame($user->id, $event->target_id);
        $this->assertSame([
            'field' => 'notify_email',
            'from' => false,
            'to' => true,
        ], $event->meta_json);

        $rawMeta = DB::table('audit_events')->where('id', $event->id)->value('meta_json');
        $this->assertSame(json_encode($event->meta_json), $rawMeta);
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

    public function test_audit_events_cannot_be_updated(): void
    {
        $event = $this->app->make(AuditLogger::class)->log('server.created');

        $this->expectException(LogicException::class);

        $event->update(['action' => 'server.deleted']);
    }

    public function test_audit_events_cannot_be_deleted(): void
    {
        $event = $this->app->make(AuditLogger::class)->log('server.created');

        $this->expectException(LogicException::class);

        $event->delete();
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
}
