<?php

namespace Tests\Feature\Ui;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentGuideTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_and_authenticated_users_can_read_the_agent_guide(): void
    {
        $this->get('/agent/guide')->assertRedirect('/login');

        $this->actingAs(User::factory()->create())
            ->get('/agent/guide')
            ->assertOk()
            ->assertSee('Agent guide')
            ->assertSee('Bash 4 or newer')
            ->assertSee('/agent/download')
            ->assertSee('--token-file /root/noc-token');
    }
}
