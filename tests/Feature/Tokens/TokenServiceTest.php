<?php

namespace Tests\Feature\Tokens;

use App\Events\ServerChanged;
use App\Models\AuditEvent;
use App\Models\Server;
use App\Models\ServerToken;
use App\Models\User;
use App\Services\Tokens\IssuedToken;
use App\Services\Tokens\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

class TokenServiceTest extends TestCase
{
    use RefreshDatabase;

    private TokenService $tokens;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tokens = $this->app->make(TokenService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_issued_plaintext_matches_the_agent_token_contract(): void
    {
        $issued = $this->tokens->issue($this->server());

        $this->assertMatchesRegularExpression('/^noc_[A-Za-z0-9_-]{43}$/', $issued->plain);
        $this->assertSame(47, strlen($issued->plain));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9._~-]{16,256}$/', $issued->plain);
        $this->assertInstanceOf(IssuedToken::class, $issued);
        $this->assertInstanceOf(ServerToken::class, $issued->token);
    }

    public function test_issue_persists_only_the_hmac_hash_and_eight_character_prefix(): void
    {
        $issued = $this->tokens->issue($this->server());

        $token = $issued->token->fresh();

        $this->assertSame(
            hash_hmac('sha256', $issued->plain, config('app.key')),
            $token->token_hash,
        );
        $this->assertSame(64, strlen($token->token_hash));
        $this->assertSame(substr($issued->plain, 0, 8), $token->token_prefix);
        $this->assertSame('noc_'.substr($issued->plain, 4, 4), $token->token_prefix);
        $this->assertNotSame($issued->plain, $token->token_hash);
    }

    public function test_the_plaintext_is_never_persisted_in_the_database(): void
    {
        $issued = $this->tokens->issue($this->server());

        $stored = DB::table('server_tokens')->get()->toJson()
            .DB::table('audit_events')->get()->toJson();

        $this->assertStringNotContainsString($issued->plain, $stored);
        $this->assertStringContainsString($issued->token->token_prefix, $stored);
    }

    public function test_two_issues_produce_different_tokens(): void
    {
        $server = $this->server();

        $first = $this->tokens->issue($server);
        $second = $this->tokens->issue($server);

        $this->assertNotSame($first->plain, $second->plain);
        $this->assertNotSame($first->token->token_hash, $second->token->token_hash);
    }

    public function test_issue_records_the_label_and_creator(): void
    {
        $actor = User::factory()->create();

        $issued = $this->tokens->issue($this->server(), $actor, 'Initial agent token');

        $token = $issued->token->fresh();

        $this->assertSame('Initial agent token', $token->label);
        $this->assertSame($actor->id, $token->created_by);
        $this->assertNull($token->expires_at);
        $this->assertNull($token->revoked_at);
        $this->assertNull($token->rotated_from_id);
    }

    public function test_authenticate_accepts_a_valid_token(): void
    {
        $issued = $this->tokens->issue($this->server());

        $resolved = $this->tokens->authenticate($issued->plain);

        $this->assertNotNull($resolved);
        $this->assertTrue($resolved->is($issued->token));
    }

    public function test_authenticate_rejects_unknown_tokens(): void
    {
        $this->tokens->issue($this->server());

        $this->assertNull($this->tokens->authenticate('noc_'.Str::repeat('a', 43)));
    }

    public function test_authenticate_rejects_revoked_tokens(): void
    {
        $issued = $this->tokens->issue($this->server());

        $this->tokens->revoke($issued->token);

        $this->assertNull($this->tokens->authenticate($issued->plain));
    }

    public function test_authenticate_rejects_expired_tokens(): void
    {
        Carbon::setTestNow('2026-11-22 12:00:00');

        $issued = $this->tokens->issue(
            $this->server(),
            expiresAt: now()->subMinute(),
        );

        $this->assertNull($this->tokens->authenticate($issued->plain));
    }

    public function test_authenticate_rejects_tokens_of_inactive_servers(): void
    {
        $issued = $this->tokens->issue($this->server(['is_active' => false]));

        $this->assertNull($this->tokens->authenticate($issued->plain));
    }

    public function test_authenticate_rejects_tokens_of_soft_deleted_servers(): void
    {
        $server = $this->server();
        $issued = $this->tokens->issue($server);

        $server->delete();

        $this->assertNull($this->tokens->authenticate($issued->plain));
    }

    public function test_resolve_accepts_a_valid_token_on_an_inactive_server(): void
    {
        $issued = $this->tokens->issue($this->server(['is_active' => false]));

        $resolved = $this->tokens->resolve($issued->plain);

        $this->assertNotNull($resolved);
        $this->assertTrue($resolved->is($issued->token));
    }

    public function test_resolve_accepts_a_valid_token_on_a_soft_deleted_server(): void
    {
        $server = $this->server();
        $issued = $this->tokens->issue($server);

        $server->delete();

        $resolved = $this->tokens->resolve($issued->plain);

        $this->assertNotNull($resolved);
        $this->assertTrue($resolved->is($issued->token));
        $this->assertNotNull($resolved->server);
        $this->assertTrue($resolved->server->trashed());
        $this->assertNull($this->tokens->authenticate($issued->plain));
    }

    public function test_resolve_ignores_server_state_but_honours_token_state(): void
    {
        $server = $this->server();

        $revoked = $this->tokens->issue($server);
        $this->tokens->revoke($revoked->token);

        $expired = $this->tokens->issue($server, expiresAt: now()->subMinute());

        $this->assertNull($this->tokens->resolve($revoked->plain));
        $this->assertNull($this->tokens->resolve($expired->plain));
    }

    public function test_malformed_tokens_return_null_without_a_database_query(): void
    {
        $malformed = [
            '',
            'nope',
            'noc_short',
            'noc_'.Str::repeat('a', 42),
            'noc_'.Str::repeat('a', 44),
            'NOC_'.Str::repeat('a', 43),
            'noc_'.Str::repeat('a', 42).'=',
            'noc_'.Str::repeat('a', 42).'+',
            'noc_'.Str::repeat('a', 42).'/',
            ' noc_'.Str::repeat('a', 43),
            'noc_'.Str::repeat('a', 43)."\n",
            'noc_'.Str::repeat('a', 43)."\r\n",
        ];

        DB::flushQueryLog();
        DB::enableQueryLog();

        foreach ($malformed as $plain) {
            $this->assertNull($this->tokens->resolve($plain));
        }

        $this->assertCount(0, DB::getQueryLog());

        DB::disableQueryLog();
    }

    public function test_rotation_keeps_the_old_token_valid_until_the_grace_period_expires(): void
    {
        Carbon::setTestNow('2026-11-22 12:00:00');

        $server = $this->server();
        $old = $this->tokens->issue($server);

        $rotated = $this->tokens->rotate($server, 1440);

        $oldToken = $old->token->fresh();

        $this->assertSame(
            now()->addMinutes(1440)->toDateTimeString(),
            $oldToken->expires_at?->toDateTimeString(),
        );
        $this->assertSame($old->token->getKey(), $rotated->token->rotated_from_id);
        $this->assertNotSame($old->plain, $rotated->plain);

        $this->assertNotNull($this->tokens->authenticate($old->plain));
        $this->assertNotNull($this->tokens->authenticate($rotated->plain));

        Carbon::setTestNow('2026-11-23 12:00:01');

        $this->assertNull($this->tokens->authenticate($old->plain));
        $this->assertNotNull($this->tokens->authenticate($rotated->plain));
    }

    public function test_rotation_uses_the_configured_grace_minutes_when_no_value_is_passed(): void
    {
        Carbon::setTestNow('2026-11-22 12:00:00');

        config(['tokens.grace_minutes' => 45]);

        $server = $this->server();
        $old = $this->tokens->issue($server);

        $this->tokens->rotate($server);

        $this->assertSame(
            now()->addMinutes(45)->toDateTimeString(),
            $old->token->fresh()->expires_at?->toDateTimeString(),
        );
    }

    public function test_rotation_never_extends_an_existing_earlier_expiry(): void
    {
        Carbon::setTestNow('2026-11-22 12:00:00');

        $server = $this->server();
        $earlier = now()->addMinutes(10);

        $old = $this->tokens->issue($server, expiresAt: $earlier);

        $this->tokens->rotate($server, 1440);

        $this->assertSame(
            $earlier->toDateTimeString(),
            $old->token->fresh()->expires_at?->toDateTimeString(),
        );
    }

    public function test_rotation_shortens_a_later_expiry_to_the_grace_window(): void
    {
        Carbon::setTestNow('2026-11-22 12:00:00');

        $server = $this->server();
        $old = $this->tokens->issue($server, expiresAt: now()->addDays(10));

        $this->tokens->rotate($server, 60);

        $this->assertSame(
            now()->addMinutes(60)->toDateTimeString(),
            $old->token->fresh()->expires_at?->toDateTimeString(),
        );
    }

    public function test_rotation_rejects_a_negative_grace_window(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->tokens->rotate($this->server(), -1);
    }

    public function test_rotation_with_a_zero_grace_window_expires_the_previous_token_immediately(): void
    {
        Carbon::setTestNow('2026-11-22 12:00:00');

        $server = $this->server();
        $old = $this->tokens->issue($server);

        $this->tokens->rotate($server, 0);

        $this->assertSame(
            now()->toDateTimeString(),
            $old->token->fresh()->expires_at?->toDateTimeString(),
        );
        $this->assertNull($this->tokens->resolve($old->plain));
    }

    public function test_rotation_without_a_usable_current_token_breaks_lineage_and_still_audits(): void
    {
        $server = $this->server();

        $rotated = $this->tokens->rotate($server, 60);

        $this->assertNull($rotated->token->rotated_from_id);
        $this->assertSame(1, AuditEvent::query()->where('action', 'token.rotated')->count());
    }

    public function test_revoke_is_idempotent_and_writes_a_single_audit_event(): void
    {
        $server = $this->server();
        $issued = $this->tokens->issue($server);

        $this->tokens->revoke($issued->token);
        $this->tokens->revoke($issued->token->fresh());

        $this->assertSame(1, AuditEvent::query()->where('action', 'token.revoked')->count());
    }

    public function test_revoke_is_immediate(): void
    {
        Carbon::setTestNow('2026-11-22 12:00:00');

        $issued = $this->tokens->issue($this->server());

        $this->assertNotNull($this->tokens->authenticate($issued->plain));

        $this->tokens->revoke($issued->token);

        $this->assertSame(
            now()->toDateTimeString(),
            $issued->token->fresh()->revoked_at?->toDateTimeString(),
        );
        $this->assertNull($this->tokens->authenticate($issued->plain));
    }

    public function test_issue_writes_a_token_created_audit_event(): void
    {
        $actor = User::factory()->create();
        $server = $this->server();

        $issued = $this->tokens->issue($server, $actor, 'Agent');

        $event = AuditEvent::query()->where('action', 'token.created')->sole();

        $this->assertSame($actor->id, $event->user_id);
        $this->assertSame($issued->token->getMorphClass(), $event->target_type);
        $this->assertSame((string) $issued->token->getKey(), $event->target_id);
        $this->assertSame(
            ['server_id', 'token_id', 'token_prefix'],
            array_keys($event->meta_json),
        );
        $this->assertSame($server->getKey(), $event->meta_json['server_id']);
        // Identifier keys survive redaction so the operator can see the
        // 8-character prefix (spec §8.1); the plaintext never reaches the log.
        $this->assertSame((string) $issued->token->getKey(), $event->meta_json['token_id']);
        $this->assertSame(26, strlen($event->meta_json['token_id']));
        $this->assertSame($issued->token->token_prefix, $event->meta_json['token_prefix']);
        $this->assertSame(8, strlen($event->meta_json['token_prefix']));
    }

    public function test_rotate_writes_a_token_rotated_audit_event(): void
    {
        $actor = User::factory()->create();
        $server = $this->server();
        $this->tokens->issue($server);

        $rotated = $this->tokens->rotate($server, 1440, $actor);

        $event = AuditEvent::query()->where('action', 'token.rotated')->sole();

        $this->assertSame($actor->id, $event->user_id);
        $this->assertSame((string) $rotated->token->getKey(), $event->target_id);
        $this->assertSame(
            ['server_id', 'token_id', 'token_prefix'],
            array_keys($event->meta_json),
        );
        $this->assertSame($server->getKey(), $event->meta_json['server_id']);
        $this->assertSame((string) $rotated->token->getKey(), $event->meta_json['token_id']);
        $this->assertSame(26, strlen($event->meta_json['token_id']));
        $this->assertSame($rotated->token->token_prefix, $event->meta_json['token_prefix']);
        $this->assertSame(8, strlen($event->meta_json['token_prefix']));
    }

    public function test_revoke_writes_a_token_revoked_audit_event(): void
    {
        $actor = User::factory()->create();
        $server = $this->server();
        $issued = $this->tokens->issue($server);

        $this->tokens->revoke($issued->token, $actor);

        $event = AuditEvent::query()->where('action', 'token.revoked')->sole();

        $this->assertSame($actor->id, $event->user_id);
        $this->assertSame((string) $issued->token->getKey(), $event->target_id);
        $this->assertSame(
            ['server_id', 'token_id', 'token_prefix'],
            array_keys($event->meta_json),
        );
        $this->assertSame($server->getKey(), $event->meta_json['server_id']);
        $this->assertSame((string) $issued->token->getKey(), $event->meta_json['token_id']);
        $this->assertSame(26, strlen($event->meta_json['token_id']));
        $this->assertSame($issued->token->token_prefix, $event->meta_json['token_prefix']);
        $this->assertSame(8, strlen($event->meta_json['token_prefix']));
    }

    public function test_rotation_emits_token_rotated(): void
    {
        config(['noc.realtime.enabled' => true]);
        Event::fake([ServerChanged::class]);

        $server = $this->server();
        $this->tokens->issue($server);

        $this->tokens->rotate($server);

        Event::assertDispatched(ServerChanged::class, fn (ServerChanged $event): bool => $event->change === 'token_rotated'
            && $event->serverId === (string) $server->getKey());
    }

    public function test_revocation_emits_token_revoked(): void
    {
        config(['noc.realtime.enabled' => true]);
        Event::fake([ServerChanged::class]);

        $server = $this->server();
        $issued = $this->tokens->issue($server);

        $this->tokens->revoke($issued->token);

        Event::assertDispatched(ServerChanged::class, fn (ServerChanged $event): bool => $event->change === 'token_revoked'
            && $event->serverId === (string) $server->getKey());
    }

    public function test_issued_token_debug_info_redacts_the_plaintext(): void
    {
        $issued = $this->tokens->issue($this->server());

        $debug = $issued->__debugInfo();

        $this->assertSame('[redacted]', $debug['plaintext']);
        $this->assertSame(substr($issued->plain, 0, 8), $debug['prefix']);
        $this->assertStringNotContainsString($issued->plain, print_r($debug, true));
    }

    private function server(array $attributes = []): Server
    {
        return Server::query()->create(array_merge([
            'name' => 'server-'.Str::lower(Str::ulid()),
            'hostname' => 'agent.example.test',
        ], $attributes));
    }
}
