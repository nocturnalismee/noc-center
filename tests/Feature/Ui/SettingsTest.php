<?php

namespace Tests\Feature\Ui;

use App\Enums\AlertRule;
use App\Issues\IssueDetector;
use App\Livewire\Settings\AlertSettings;
use App\Livewire\Settings\Profile;
use App\Models\AuditEvent;
use App\Models\MetricSample;
use App\Models\Server;
use App\Models\Setting;
use App\Models\User;
use App\Services\SettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-23 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_the_settings_hub_is_role_aware_and_never_returns_a_404(): void
    {
        $viewer = User::factory()->create();
        $operator = User::factory()->create(['role' => 'operator']);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->get('/settings')->assertRedirect('/login');

        $this->actingAs($viewer)
            ->get('/settings')
            ->assertOk()
            ->assertSee('Settings')
            ->assertSee('href="'.route('settings.profile').'"', false)
            ->assertSee('href="/settings/security"', false)
            ->assertDontSee('href="'.route('settings.users').'"', false)
            ->assertDontSee('href="'.route('settings.alerts').'"', false)
            ->assertDontSee('href="'.route('activity.index').'"', false);

        $this->actingAs($operator)
            ->get('/settings')
            ->assertOk()
            ->assertSee('href="'.route('settings.profile').'"', false)
            ->assertSee('href="'.route('activity.index').'"', false)
            ->assertDontSee('href="'.route('settings.users').'"', false)
            ->assertDontSee('href="'.route('settings.alerts').'"', false);

        $this->actingAs($admin)
            ->get('/settings')
            ->assertOk()
            ->assertSee('href="'.route('settings.profile').'"', false)
            ->assertSee('href="/settings/security"', false)
            ->assertSee('href="'.route('settings.users').'"', false)
            ->assertSee('href="'.route('settings.alerts').'"', false)
            ->assertSee('href="'.route('activity.index').'"', false);
    }

    public function test_settings_pages_share_the_settings_sub_navigation(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get('/settings/profile')
            ->assertOk()
            ->assertSee('aria-label="Settings sections"', false)
            ->assertSee('href="'.route('settings.users').'"', false)
            ->assertSee('href="'.route('settings.alerts').'"', false);

        $this->actingAs($admin)
            ->get('/settings/users')
            ->assertOk()
            ->assertSee('aria-label="Settings sections"', false);
    }

    public function test_a_flagged_user_is_redirected_to_the_profile_until_the_password_changes(): void
    {
        $flagged = User::factory()->create([
            'role' => 'admin',
            'must_change_password' => true,
        ]);

        $this->actingAs($flagged)->get('/dashboard')->assertRedirect('/settings/profile');
        $this->actingAs($flagged)->get('/settings')->assertRedirect('/settings/profile');
        $this->actingAs($flagged)->get('/settings/users')->assertRedirect('/settings/profile');
        $this->actingAs($flagged)->get('/activity')->assertRedirect('/settings/profile');
        $this->actingAs($flagged)->get('/settings/profile')->assertOk();

        // Livewire endpoints must not be redirected or every form request
        // would loop back to the profile page.
        $livewire = $this->actingAs($flagged)->post(route('default-livewire.update'));
        $this->assertFalse($livewire->isRedirect('/settings/profile'));

        $this->actingAs($flagged)->post('/logout')->assertRedirect();

        $flagged->refresh();
        $flagged->forceFill(['must_change_password' => false])->save();

        $this->actingAs($flagged)->get('/dashboard')->assertOk();
    }

    public function test_profile_updates_name_and_notification_preference_without_a_password(): void
    {
        $user = User::factory()->create([
            'name' => 'Old Name',
            'email' => 'profile@example.com',
            'notify_email' => true,
        ]);

        $this->actingAs($user);

        Livewire::test(Profile::class)
            ->set('name', 'New Name')
            ->set('notifyEmail', false)
            ->call('saveProfile')
            ->assertHasNoErrors();

        $user->refresh();

        $this->assertSame('New Name', $user->name);
        $this->assertFalse($user->notify_email);
        $this->assertSame('profile@example.com', $user->email);
        $this->assertTrue(AuditEvent::query()->where('action', 'user.profile_updated')->exists());
    }

    public function test_changing_the_email_requires_the_current_password_and_resets_verification(): void
    {
        $user = User::factory()->create([
            'email' => 'old@example.com',
            'email_verified_at' => now()->subDay(),
        ]);

        $this->actingAs($user);

        Livewire::test(Profile::class)
            ->set('email', 'new@example.com')
            ->call('saveProfile')
            ->assertHasErrors('emailPassword');

        $this->assertSame('old@example.com', $user->fresh()->email);

        Livewire::test(Profile::class)
            ->set('email', 'new@example.com')
            ->set('emailPassword', 'password')
            ->call('saveProfile')
            ->assertHasNoErrors();

        $user->refresh();

        $this->assertSame('new@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
        $this->assertTrue(AuditEvent::query()->where('action', 'user.email_changed')->exists());
    }

    public function test_profile_emails_are_trimmed_lowercased_and_unique_case_insensitively(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $user = User::factory()->create([
            'email' => 'profile.owner@example.com',
            'email_verified_at' => now()->subDay(),
        ]);

        $this->actingAs($user);

        Livewire::test(Profile::class)
            ->set('email', '  New.Address@Example.COM  ')
            ->set('emailPassword', 'password')
            ->call('saveProfile')
            ->assertHasNoErrors();

        $this->assertSame('new.address@example.com', $user->fresh()->email);

        Livewire::test(Profile::class)
            ->set('email', 'TAKEN@EXAMPLE.COM')
            ->set('emailPassword', 'password')
            ->call('saveProfile')
            ->assertHasErrors('email');

        $this->assertSame('new.address@example.com', $user->fresh()->email);
    }

    public function test_a_failed_password_change_clears_all_password_fields_from_the_snapshot(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $component = Livewire::test(Profile::class)
            ->set('currentPassword', 'password')
            ->set('newPassword', 'short')
            ->set('newPasswordConfirmation', 'short')
            ->call('savePassword')
            ->assertHasErrors('newPassword')
            ->assertSet('currentPassword', '')
            ->assertSet('newPassword', '')
            ->assertSet('newPasswordConfirmation', '');

        $snapshot = json_encode($component->getData());

        $this->assertStringNotContainsString('"currentPassword":"password"', $snapshot);
        $this->assertStringNotContainsString('"newPassword":"short"', $snapshot);
        $this->assertStringNotContainsString('"newPasswordConfirmation":"short"', $snapshot);
        $this->assertStringNotContainsString('"currentPassword":"password"', $component->html());
    }

    public function test_password_change_requires_the_current_password_and_clears_the_flag(): void
    {
        $user = User::factory()->create(['must_change_password' => true]);

        $this->actingAs($user);

        Livewire::test(Profile::class)
            ->set('currentPassword', 'wrong-password')
            ->set('newPassword', 'brand-new-password-1')
            ->set('newPasswordConfirmation', 'brand-new-password-1')
            ->call('savePassword')
            ->assertHasErrors('currentPassword');

        $this->assertTrue(Hash::check('password', $user->fresh()->password));

        Livewire::test(Profile::class)
            ->set('currentPassword', 'password')
            ->set('newPassword', 'brand-new-password-1')
            ->set('newPasswordConfirmation', 'brand-new-password-1')
            ->call('savePassword')
            ->assertHasNoErrors();

        $user->refresh();

        $this->assertTrue(Hash::check('brand-new-password-1', $user->password));
        $this->assertFalse($user->must_change_password);

        $audit = AuditEvent::query()->where('action', 'user.password_changed')->sole();
        $this->assertStringNotContainsString('brand-new-password-1', (string) json_encode($audit->meta_json));
    }

    public function test_self_service_password_change_keeps_the_current_session_and_purges_others(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);
        $this->get('/settings/profile')->assertOk();

        $current = session()->getId();
        $remember = $user->remember_token;

        foreach (['current-session', 'other-session'] as $id) {
            DB::table('sessions')->insert([
                'id' => $id,
                'user_id' => $user->id,
                'ip_address' => '203.0.113.10',
                'user_agent' => 'PHPUnit',
                'payload' => 'payload',
                'last_activity' => now()->timestamp,
            ]);
        }

        $this->assertNotSame('', $current);

        // Keep the real current row under the real session id.
        DB::table('sessions')->where('id', 'current-session')->update(['id' => $current]);

        Livewire::test(Profile::class)
            ->set('currentPassword', 'password')
            ->set('newPassword', 'brand-new-password-1')
            ->set('newPasswordConfirmation', 'brand-new-password-1')
            ->call('savePassword')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('sessions', ['id' => $current]);
        $this->assertDatabaseMissing('sessions', ['id' => 'other-session']);
        $this->assertNotSame($remember, $user->fresh()->remember_token);
    }

    public function test_profile_password_changes_require_at_least_twelve_characters(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        Livewire::test(Profile::class)
            ->set('currentPassword', 'password')
            ->set('newPassword', 'elevenchars')
            ->set('newPasswordConfirmation', 'elevenchars')
            ->call('savePassword')
            ->assertHasErrors('newPassword');
    }

    public function test_alert_settings_are_admin_only_and_a_threshold_override_changes_detection(): void
    {
        $operator = User::factory()->create(['role' => 'operator']);
        $viewer = User::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($operator)->get('/settings/alerts')->assertForbidden();
        $this->actingAs($viewer)->get('/settings/alerts')->assertForbidden();

        $this->actingAs($admin)
            ->get('/settings/alerts')
            ->assertOk()
            ->assertSee('Alert settings')
            ->assertSee('Reminder hours');

        $server = $this->server();
        $latest = null;

        foreach ([12, 11, 10] as $minutesAgo) {
            $latest = $this->sample($server, 60.0, now()->subMinutes($minutesAgo));
        }

        $this->assertSame([], app(IssueDetector::class)->detect($server, $latest, [], [])->codes);

        $this->actingAs($admin);

        Livewire::test(AlertSettings::class)
            ->set('rules.cpu_high.warning', 50)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(50, app(SettingsRepository::class)->get('alerts.rules.cpu_high.warning'));

        $this->assertSame(
            [AlertRule::CpuHigh->value],
            app(IssueDetector::class)->detect($server, $latest, [], [])->codes,
        );
    }

    public function test_a_telegram_bot_token_is_write_only_and_stored_as_ciphertext(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $token = '123456789:super-secret-bot-token-value-abcdefghijkl';

        $this->actingAs($admin);

        Livewire::test(AlertSettings::class)
            ->set('telegramEnabled', true)
            ->set('chatId', '-1001234567890')
            ->set('messageThreadId', '7')
            ->call('replaceToken')
            ->set('botToken', $token)
            ->call('save')
            ->assertHasNoErrors();

        $setting = Setting::query()->findOrFail('alerts.channels.telegram.bot_token');

        $this->assertTrue($setting->is_secret);
        $this->assertNotSame($token, $setting->getRawOriginal('value'));

        $repository = app(SettingsRepository::class);
        $this->assertSame($token, $repository->get('alerts.channels.telegram.bot_token'));
        $this->assertTrue($repository->hasSecret('alerts.channels.telegram.bot_token'));

        $this->actingAs($admin)
            ->get('/settings/alerts')
            ->assertOk()
            ->assertSee('Configured')
            ->assertSee('…4567890')
            ->assertDontSee('-1001234567890')
            ->assertDontSee($token);

        foreach (AuditEvent::query()->where('action', 'settings.updated')->get() as $event) {
            $this->assertStringNotContainsString($token, (string) json_encode($event->meta_json));
        }

        Livewire::test(AlertSettings::class)
            ->call('clearToken')
            ->assertHasNoErrors();

        $this->assertFalse($repository->hasSecret('alerts.channels.telegram.bot_token'));
        $this->assertNull($repository->get('alerts.channels.telegram.bot_token'));
    }

    public function test_a_failed_alert_settings_save_clears_the_bot_token_from_the_snapshot(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $token = '123456789:super-secret-bot-token-value-abcdefghijkl';

        $this->actingAs($admin);

        $component = Livewire::test(AlertSettings::class)
            ->set('reminderHours', 'not-a-number')
            ->set('botToken', $token)
            ->call('save')
            ->assertHasErrors('reminderHours')
            ->assertSet('botToken', '');

        $this->assertStringNotContainsString($token, json_encode($component->getData()));
        $this->assertStringNotContainsString($token, $component->html());
    }

    public function test_telegram_chat_and_thread_ids_are_validated(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin);

        Livewire::test(AlertSettings::class)
            ->set('telegramEnabled', true)
            ->set('chatId', 'not a chat id')
            ->set('messageThreadId', '0')
            ->call('save')
            ->assertHasErrors(['chatId', 'messageThreadId']);

        Livewire::test(AlertSettings::class)
            ->set('telegramEnabled', false)
            ->set('chatId', '@noc_channel')
            ->set('messageThreadId', '')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('@noc_channel', app(SettingsRepository::class)->get('alerts.channels.telegram.chat_id'));
        $this->assertNull(app(SettingsRepository::class)->get('alerts.channels.telegram.message_thread_id'));
    }

    public function test_the_smtp_status_never_renders_the_mail_password(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp.example.com',
            'mail.mailers.smtp.port' => 587,
            'mail.mailers.smtp.encryption' => 'tls',
            'mail.mailers.smtp.username' => 'alerts@example.com',
            'mail.mailers.smtp.password' => 'smtp-secret-password',
            'mail.from.address' => 'alerts@example.com',
            'mail.from.name' => 'NOC Center',
        ]);

        $this->actingAs($admin)
            ->get('/settings/alerts')
            ->assertOk()
            ->assertSee('smtp.example.com')
            ->assertSee('alerts@example.com')
            ->assertDontSee('smtp-secret-password');
    }

    public function test_send_test_is_admin_only_rate_limited_and_audited(): void
    {
        Notification::fake();

        $admin = User::factory()->create(['role' => 'admin']);
        $operator = User::factory()->create(['role' => 'operator']);

        $this->actingAs($operator);

        $this->get('/settings/alerts')->assertForbidden();

        $this->actingAs($admin);

        $component = Livewire::test(AlertSettings::class);

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $component->call('sendTest', 'email')->assertHasNoErrors();
        }

        $component->call('sendTest', 'email')->assertHasErrors('channel');

        $this->assertSame(3, AuditEvent::query()->where('action', 'alert_channel.tested')->count());
    }

    public function test_a_failing_telegram_test_returns_a_redacted_error(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $token = '123456789:super-secret-bot-token-value-abcdefghijkl';
        $repository = app(SettingsRepository::class);

        $repository->set('alerts.channels.telegram.enabled', true, 'bool');
        $repository->set('alerts.channels.telegram.chat_id', '-1001234567890');
        $repository->set('alerts.channels.telegram.bot_token', $token, 'string', true);

        Http::fake(function () use ($token): void {
            throw new ConnectionException('POST https://api.telegram.org/bot'.$token.'/sendMessage failed');
        });

        $this->actingAs($admin);

        $component = Livewire::test(AlertSettings::class)
            ->call('sendTest', 'telegram')
            ->assertHasErrors('channel');

        $this->assertStringNotContainsString($token, (string) $component->errors()->first('channel'));
        $this->assertStringNotContainsString($token, $component->html());
        $this->assertSame(0, AuditEvent::query()->where('action', 'alert_channel.tested')->count());
    }

    private function server(): Server
    {
        return Server::query()->create([
            'name' => 'server-'.Str::lower(Str::ulid()),
            'hostname' => 'agent.example.test',
            'server_type' => 'Web',
            'alerts_enabled' => true,
            'notes' => null,
            'interval_seconds' => 60,
            'health' => 'unknown',
            'issue_codes' => [],
            'is_active' => true,
            'last_seen_at' => null,
            'stale_after' => null,
            'offline_after' => null,
        ]);
    }

    private function sample(Server $server, float $cpu, Carbon $at): MetricSample
    {
        return $server->samples()->create([
            'collected_at' => $at,
            'received_at' => $at,
            'schema_version' => 1,
            'interval_seconds' => 60,
            'cpu_usage_pct' => $cpu,
            'cpu_iowait_pct' => 0.1,
            'cpu_cores' => 8,
            'load1' => 0.5,
            'load5' => 0.5,
            'load15' => 0.5,
            'mem_total_bytes' => 1_000,
            'mem_used_bytes' => 100,
            'mem_used_pct' => 10.0,
            'swap_total_bytes' => 0,
            'swap_used_bytes' => 0,
            'swap_used_pct' => 0,
            'disk_total_bytes' => 1_000,
            'disk_used_bytes' => 100,
            'disk_used_pct' => 10.0,
            'disk_inode_pct' => 10.0,
            'net_rx_bps' => 0,
            'net_tx_bps' => 0,
            'processes_running' => 1,
            'processes_blocked' => 0,
            'uptime_seconds' => 100,
            'payload_bytes' => 10,
        ])->refresh();
    }
}
