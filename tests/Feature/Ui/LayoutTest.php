<?php

namespace Tests\Feature\Ui;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
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
            'dashboard',
            'servers.index',
            'alerts.index',
            'activity.index',
            'settings.index',
            'settings.users',
        ] as $name) {
            $response->assertSee('href="'.route($name).'"', false);
        }

        $response->assertSee('aria-current="page"', false);

        foreach (['Dashboard', 'Servers', 'Alerts', 'Activity', 'Settings', 'Users'] as $label) {
            $response->assertSee('aria-label="'.$label.'"', false);
        }

        $response->assertDontSee('aria-label="Audit log"', false);
        $response->assertSee('data-search-hint', false);

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

    public function test_the_layout_renders_public_realtime_configuration_without_the_app_secret(): void
    {
        config([
            'noc.realtime.enabled' => true,
            'reverb.apps.apps.0.key' => 'test-key',
            'reverb.apps.apps.0.secret' => 'test-secret',
            'reverb.apps.apps.0.options.host' => 'noc.example.com',
            'reverb.apps.apps.0.options.port' => 443,
            'reverb.apps.apps.0.options.scheme' => 'https',
        ]);

        $response = $this->actingAs(User::factory()->create())->get('/dashboard');

        $response->assertOk();
        $response->assertSee('window.__NOC_REALTIME__', false);
        $response->assertSee('"enabled":true', false);
        $response->assertSee('"key":"test-key"', false);
        $response->assertSee('"host":"noc.example.com"', false);
        $response->assertSee('"port":443', false);
        $response->assertSee('"scheme":"https"', false);
        $response->assertDontSee('test-secret');
    }

    public function test_the_topbar_search_submits_a_query_to_the_server_list(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('action="'.route('servers.index').'"', false)
            ->assertSee('name="q"', false)
            ->assertSee('placeholder="Search servers"', false);
    }

    public function test_the_status_toast_is_a_fixed_live_region(): void
    {
        $html = Blade::render('<x-status-toast message="Alert settings saved." />');

        $this->assertStringContainsString('data-status-toast', $html);
        $this->assertStringContainsString('role="status"', $html);
        $this->assertStringContainsString('aria-live="polite"', $html);
        $this->assertStringContainsString('fixed', $html);
        $this->assertStringContainsString('Alert settings saved.', $html);

        $this->assertSame('', trim(Blade::render('<x-status-toast />')));
    }

    public function test_a_viewer_does_not_see_the_activity_sidebar_link(): void
    {
        $viewer = User::factory()->create();

        $this->actingAs($viewer)
            ->get('/dashboard')
            ->assertOk()
            ->assertDontSee('href="'.route('activity.index').'"', false)
            ->assertDontSee('aria-label="Activity"', false);
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
            'stale' => 'Stale',
            'offline' => 'Offline',
            'never' => 'Never seen',
            'never_seen' => 'Never seen',
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

    public function test_the_tablet_rail_is_forced_and_the_collapse_control_is_hidden(): void
    {
        $css = (string) file_get_contents(resource_path('css/app.css'));

        $this->assertMatchesRegularExpression(
            '/@media \(min-width: 768px\) and \(max-width: 1023px\) \{\n(?<block>.*?)\n\}/s',
            $css,
            'The 768-1023px forced-rail media query is missing.',
        );

        preg_match('/@media \(min-width: 768px\) and \(max-width: 1023px\) \{\n(?<block>.*?)\n\}/s', $css, $matches);
        $block = $matches['block'];

        $this->assertStringContainsString('grid-template-columns: 64px minmax(0, 1fr)', $block);

        foreach ([
            '.sidebar-label',
            '.sidebar-group-label',
            '.sidebar-brand-copy',
            '.sidebar-footer-copy',
        ] as $selector) {
            $this->assertMatchesRegularExpression(
                '/'.preg_quote($selector, '/').'[^{]*\{[^}]*display: none/',
                $block,
                $selector.' is not hidden in the forced rail.',
            );
        }

        $this->assertMatchesRegularExpression('/\.nav-link \{[^}]*justify-content: center/', $block);

        $view = (string) file_get_contents(resource_path('views/layouts/app.blade.php'));

        $this->assertMatchesRegularExpression(
            '/<div class="sidebar-collapse [^"]*\bhidden\b[^"]*\blg:block\b/',
            $view,
            'The collapse control must be hidden below lg.',
        );
        $this->assertMatchesRegularExpression('/<button[^>]*data-sidebar-toggle[^>]*>/s', $view);
    }

    public function test_the_content_gutter_is_sixteen_pixels_below_1024(): void
    {
        $view = (string) file_get_contents(resource_path('views/layouts/app.blade.php'));

        preg_match('/<main\b[^>]*class="(?<class>[^"]*)"/s', $view, $main);
        preg_match('/<footer\b[^>]*class="(?<class>[^"]*)"/s', $view, $footer);

        foreach (['main' => $main['class'], 'footer' => $footer['class']] as $element => $classes) {
            $tokens = preg_split('/\s+/', $classes);

            $this->assertContains('px-4', $tokens, $element.' must use the 16px gutter by default.');
            $this->assertContains('lg:px-6', $tokens, $element.' must use the 24px gutter from 1024px.');
            $this->assertContains('xl:px-8', $tokens, $element.' must use the 32px gutter from 1280px.');
            $this->assertNotContains('md:px-6', $tokens, $element.' must not force 24px at 768px.');
        }
    }

    public function test_the_sidebar_footer_never_reduces_to_a_lone_chevron(): void
    {
        $view = (string) file_get_contents(resource_path('views/layouts/app.blade.php'));

        $this->assertStringContainsString('sidebar-footer-avatar', $view);
        $this->assertStringContainsString('sidebar-footer-chevron', $view);

        $css = (string) file_get_contents(resource_path('css/app.css'));

        $this->assertMatchesRegularExpression(
            '/\.sidebar-footer-avatar \{[^}]*display: none/',
            $css,
            'The avatar must be hidden in the expanded sidebar.',
        );
        $this->assertMatchesRegularExpression(
            "/html\\[data-sidebar='collapsed'\\] \\.sidebar-footer-chevron \\{[^}]*display: none/",
            $css,
        );
        $this->assertMatchesRegularExpression(
            "/html\\[data-sidebar='collapsed'\\] \\.sidebar-footer-avatar \\{[^}]*display: flex/",
            $css,
        );
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

    public function test_no_view_ships_placeholder_copy(): void
    {
        $phrases = [
            'arrives in the next task',
            'coming soon',
            'not implemented yet',
            'placeholder page',
            'under construction',
            'this shell is the shared application frame',
        ];

        foreach (File::allFiles(resource_path('views')) as $file) {
            $contents = strtolower((string) file_get_contents($file->getPathname()));

            foreach ($phrases as $phrase) {
                $this->assertStringNotContainsString(
                    $phrase,
                    $contents,
                    $file->getRelativePathname().' still contains placeholder copy: '.$phrase,
                );
            }
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
