<?php

namespace Tests\Feature\Ui;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class LayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_root_redirects_to_the_dashboard(): void
    {
        $this->get('/')->assertRedirect('/dashboard');
    }

    public function test_the_authenticated_dashboard_renders_the_application_shell(): void
    {
        $user = User::factory()->create([
            'name' => 'Andi Pratama',
            'role' => 'admin',
        ]);

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('href="#main"', false);
        $response->assertSee('id="main"', false);

        foreach ([
            '/dashboard',
            '/servers',
            '/alerts',
            '/activity',
            '/settings',
            '/users',
            '/audit-log',
        ] as $path) {
            $response->assertSee('href="'.$path.'"', false);
        }

        $response->assertSee('aria-current="page"', false);
        $response->assertSee('data-sidebar-toggle', false);
        $response->assertSee('data-sidebar-open', false);
        $response->assertSee('data-theme-toggle', false);
        $response->assertSee('Andi Pratama');
        $response->assertSee('Administrator');
        $response->assertSee('data-sidebar-backdrop', false);

        $html = (string) $response->getContent();
        $this->assertStringContainsString("localStorage.getItem('theme')", $html);
        $this->assertStringContainsString("localStorage.getItem('sidebar')", $html);

        $bootstrap = $this->bootstrapScript($html);

        $this->assertNotNull($bootstrap, 'The inline theme/sidebar bootstrap script is missing.');
        $this->assertMatchesRegularExpression('/\bnonce="[^"]+"/', $bootstrap);

        foreach (['fonts.bunny.net', 'fonts.googleapis.com', 'fonts.gstatic.com', 'cdn.jsdelivr.net', 'unpkg.com'] as $host) {
            $this->assertStringNotContainsString($host, $html);
        }
    }

    public function test_the_mobile_menu_control_has_a_forty_four_pixel_target(): void
    {
        $user = User::factory()->create();

        $html = (string) $this->actingAs($user)->get('/dashboard')->getContent();

        $this->assertMatchesRegularExpression('/<button[^>]*data-sidebar-open[^>]*>/s', $html);
        preg_match('/<button[^>]*data-sidebar-open[^>]*>/s', $html, $match);
        $this->assertMatchesRegularExpression('/\b(?:h-11|min-h-11|size-11)\b/', $match[0]);
    }

    public function test_the_status_component_renders_text_and_a_dot_for_each_state(): void
    {
        $expectations = [
            'online' => 'Online',
            'warning' => 'Warning',
            'critical' => 'Critical',
            'unknown' => 'Unknown',
        ];

        foreach ($expectations as $state => $label) {
            $html = Blade::render('<x-status state="'.$state.'" />');

            $this->assertStringContainsString($label, $html);
            $this->assertStringContainsString('data-state="'.$state.'"', $html);
            $this->assertStringContainsString('rounded-full', $html);
            $this->assertStringContainsString('aria-hidden="true"', $html);
        }
    }

    public function test_the_acknowledged_status_is_text_only_and_neutral(): void
    {
        $html = Blade::render('<x-status state="acknowledged" />');

        $this->assertStringContainsString('Acknowledged', $html);
        $this->assertStringContainsString('text-text-sub', $html);
        $this->assertStringNotContainsString('rounded-full', $html);
    }

    public function test_the_status_component_accepts_a_label_override(): void
    {
        $html = Blade::render('<x-status state="online" label="Reporting" />');

        $this->assertStringContainsString('Reporting', $html);
    }

    public function test_the_empty_state_component_renders_one_sentence_and_an_action(): void
    {
        $html = Blade::render(
            '<x-empty-state>No server has reported yet.<x-slot:action><a href="/servers/create">Add server</a></x-slot:action></x-empty-state>'
        );

        $this->assertStringContainsString('No server has reported yet.', $html);
        $this->assertStringContainsString('href="/servers/create"', $html);
        $this->assertStringContainsString('Add server', $html);
    }

    public function test_the_sign_in_screen_renders_inside_the_guest_layout(): void
    {
        $response = $this->get('/login');

        $response->assertOk();
        $response->assertSee('Sign in');
        $response->assertSee('Use your NOC Center account to continue.');
        $response->assertSee('Need access? Contact your administrator.');
        $response->assertSee('name="email"', false);
        $response->assertSee('name="password"', false);
        $response->assertSee('name="remember"', false);
        $response->assertSee('autocomplete="username"', false);
        $response->assertSee('autocomplete="current-password"', false);
        $response->assertSee('data-theme-toggle', false);
        $response->assertSee('Accounts are created by administrators.');
        $response->assertSee('Read the access policy');
        $response->assertSee(route('login.store'), false);
        $response->assertSee(route('password.request'), false);

        $response->assertDontSee('data-sidebar-toggle', false);
        $response->assertDontSee('Audit log');
        $response->assertDontSee('OPERATIONS CONSOLE');
    }

    public function test_the_sign_in_screen_preserves_old_input_and_the_error_summary(): void
    {
        $this->from('/login')
            ->post('/login', ['email' => 'not-an-email', 'password' => ''])
            ->assertRedirect('/login');

        $this->get('/login')
            ->assertSee('role="alert"', false)
            ->assertSee('value="not-an-email"', false);
    }

    public function test_the_password_screens_keep_their_fortify_forms(): void
    {
        $this->get('/forgot-password')
            ->assertOk()
            ->assertSee(route('password.email'), false)
            ->assertSee('Need access? Contact your administrator.');

        $this->get('/reset-password/dummy-token')
            ->assertOk()
            ->assertSee(route('password.update'), false)
            ->assertSee('name="token"', false)
            ->assertSee('value="dummy-token"', false);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/user/confirm-password')
            ->assertOk()
            ->assertSee(route('password.confirm.store'), false);
    }

    public function test_the_ui_views_do_not_hardcode_colour_values(): void
    {
        $paths = [
            resource_path('views/layouts/app.blade.php'),
            resource_path('views/layouts/guest.blade.php'),
            resource_path('views/components/status.blade.php'),
            resource_path('views/components/empty-state.blade.php'),
            resource_path('views/dashboard-placeholder.blade.php'),
            resource_path('views/auth/login.blade.php'),
            resource_path('views/auth/forgot-password.blade.php'),
            resource_path('views/auth/reset-password.blade.php'),
            resource_path('views/auth/confirm-password.blade.php'),
        ];

        foreach ($paths as $path) {
            $contents = (string) file_get_contents($path);

            $this->assertDoesNotMatchRegularExpression('/#[0-9a-fA-F]{3,8}\b/', $contents, basename($path).' hardcodes a colour value.');
        }
    }

    private function bootstrapScript(string $html): ?string
    {
        preg_match_all('/<script\b(?<attributes>[^>]*)>(?<body>.*?)<\/script>/is', $html, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            if (preg_match('/\bsrc\s*=/i', $match['attributes']) === 1) {
                continue;
            }

            if (str_contains($match['body'], 'localStorage.getItem')) {
                return $match[0];
            }
        }

        return null;
    }
}
