<?php

namespace Tests\Feature\Realtime;

use App\Enums\AlertRule;
use App\Enums\AlertSeverity;
use App\Enums\AlertStatus;
use App\Models\Alert;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChannelAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
            'broadcasting.connections.reverb.options.host' => 'localhost',
            'broadcasting.connections.reverb.options.port' => 8080,
            'broadcasting.connections.reverb.options.scheme' => 'http',
            'broadcasting.connections.reverb.options.useTLS' => false,
        ]);

        // The application boots with the test suite's null broadcaster, so
        // channel callbacks were registered on that connection. Re-register
        // them on the Reverb connection before exercising /broadcasting/auth.
        require base_path('routes/channels.php');
    }

    public function test_an_active_user_can_join_the_fleet_channel(): void
    {
        $this->actingAs(User::factory()->create())
            ->post('/broadcasting/auth', $this->authPayload('private-fleet'))
            ->assertOk()
            ->assertJsonStructure(['auth']);
    }

    public function test_an_inactive_user_is_signed_out_instead_of_authorized(): void
    {
        $user = User::factory()->create(['is_active' => false]);

        $this->actingAs($user)
            ->post('/broadcasting/auth', $this->authPayload('private-fleet'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_a_viewer_can_join_an_authorized_server_channel(): void
    {
        $server = $this->server();

        $this->actingAs(User::factory()->create())
            ->post('/broadcasting/auth', $this->authPayload('private-server.'.$server->id))
            ->assertOk()
            ->assertJsonStructure(['auth']);
    }

    public function test_a_viewer_can_join_an_authorized_alert_channel(): void
    {
        $alert = $this->alert($this->server());

        $this->actingAs(User::factory()->create())
            ->post('/broadcasting/auth', $this->authPayload('private-alert.'.$alert->id))
            ->assertOk()
            ->assertJsonStructure(['auth']);
    }

    public function test_an_unknown_server_channel_is_not_authorized(): void
    {
        $this->actingAs(User::factory()->create())
            ->post('/broadcasting/auth', $this->authPayload('private-server.01JUNKUNKNOWN'))
            ->assertStatus(404);
    }

    private function server(): Server
    {
        return Server::create([
            'name' => 'web-1',
            'hostname' => 'web-1.example.test',
        ]);
    }

    private function alert(Server $server): Alert
    {
        return Alert::create([
            'server_id' => $server->id,
            'rule_key' => AlertRule::CpuHigh,
            'severity' => AlertSeverity::Warning,
            'status' => AlertStatus::Open,
            'active_key' => $server->id.':cpu_high',
            'title' => 'CPU usage high',
            'message' => 'CPU usage is above the warning threshold.',
            'context_json' => ['value' => 91.5, 'threshold' => 90],
            'first_seen_at' => now()->subMinute(),
            'last_seen_at' => now(),
        ]);
    }

    /**
     * @return array{channel_name: string, socket_id: string}
     */
    private function authPayload(string $channel): array
    {
        return [
            'channel_name' => $channel,
            'socket_id' => '1234.5678',
        ];
    }
}
