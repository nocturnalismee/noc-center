<?php

namespace Tests\Feature\Security;

use App\Http\Middleware\EnsurePasswordChanged;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class PasswordChangeMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    private User $flagged;

    protected function setUp(): void
    {
        parent::setUp();

        $this->flagged = User::factory()->create([
            'role' => 'admin',
            'must_change_password' => true,
        ]);
    }

    public function test_a_get_livewire_asset_request_is_allowed(): void
    {
        $response = $this->handleRequest(Request::create('/livewire-1234/livewire.js', 'GET'));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_a_profile_livewire_update_is_allowed(): void
    {
        $response = $this->handleRequest($this->updateRequest('settings.profile'));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_an_alert_settings_livewire_update_is_denied(): void
    {
        $response = $this->handleRequest($this->updateRequest('settings.alert-settings'));

        $this->assertTrue($response->isRedirect(route('settings.profile')));
    }

    public function test_a_payload_without_components_is_denied(): void
    {
        $response = $this->handleRequest($this->jsonRequest('/livewire-1234/update', ['components' => []]));

        $this->assertTrue($response->isRedirect(route('settings.profile')));
    }

    public function test_a_malformed_snapshot_is_denied(): void
    {
        $response = $this->handleRequest($this->jsonRequest('/livewire-1234/update', [
            'components' => [
                ['snapshot' => '{not-json', 'updates' => [], 'calls' => []],
            ],
        ]));

        $this->assertTrue($response->isRedirect(route('settings.profile')));
    }

    public function test_a_snapshot_without_a_memo_name_is_denied(): void
    {
        $response = $this->handleRequest($this->jsonRequest('/livewire-1234/update', [
            'components' => [
                ['snapshot' => json_encode(['data' => [], 'memo' => ['id' => 'abc'], 'checksum' => 'x']), 'updates' => [], 'calls' => []],
            ],
        ]));

        $this->assertTrue($response->isRedirect(route('settings.profile')));
    }

    public function test_one_foreign_component_in_the_payload_denies_the_whole_update(): void
    {
        $response = $this->handleRequest($this->jsonRequest('/livewire-1234/update', [
            'components' => [
                ['snapshot' => $this->snapshot('settings.profile'), 'updates' => [], 'calls' => []],
                ['snapshot' => $this->snapshot('alerts.alert-bell'), 'updates' => [], 'calls' => []],
            ],
        ]));

        $this->assertTrue($response->isRedirect(route('settings.profile')));
    }

    public function test_a_flagged_user_can_still_reach_the_profile_route(): void
    {
        $this->actingAs($this->flagged)
            ->get('/settings/profile')
            ->assertOk();
    }

    public function test_the_real_profile_page_snapshot_uses_the_allowed_component_name(): void
    {
        $html = (string) $this->actingAs($this->flagged)
            ->get('/settings/profile')
            ->assertOk()
            ->getContent();

        preg_match_all('/wire:snapshot="([^"]*)"/', $html, $matches);

        $names = [];

        foreach ($matches[1] as $encoded) {
            $snapshot = json_decode(html_entity_decode($encoded, ENT_QUOTES | ENT_HTML5), true);

            if (is_array($snapshot) && is_string($snapshot['memo']['name'] ?? null)) {
                $names[] = $snapshot['memo']['name'];
            }
        }

        $this->assertContains('settings.profile', $names);
    }

    private function updateRequest(string $component): Request
    {
        return $this->jsonRequest('/livewire-1234/update', [
            'components' => [
                ['snapshot' => $this->snapshot($component), 'updates' => [], 'calls' => []],
            ],
        ]);
    }

    private function jsonRequest(string $path, array $payload): Request
    {
        $request = Request::create(
            $path,
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_LIVEWIRE' => 'true'],
            json_encode($payload, JSON_THROW_ON_ERROR),
        );

        $request->setUserResolver(fn (): User => $this->flagged);

        return $request;
    }

    private function handleRequest(Request $request): Response
    {
        $request->setUserResolver(fn (): User => $this->flagged);

        return (new EnsurePasswordChanged)->handle($request, fn (): Response => response('ok'));
    }

    private function snapshot(string $component): string
    {
        return json_encode([
            'data' => [],
            'memo' => ['id' => 'abc', 'name' => $component],
            'checksum' => 'checksum',
        ], JSON_THROW_ON_ERROR);
    }
}
