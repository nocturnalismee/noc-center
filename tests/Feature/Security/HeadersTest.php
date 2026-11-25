<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class HeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_dashboard_sends_every_security_header(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $this->assertSecurityHeaders($response);
    }

    public function test_the_sign_in_screen_sends_every_security_header(): void
    {
        $response = $this->get('/login');

        $response->assertOk();
        $this->assertSecurityHeaders($response);
    }

    public function test_an_unmatched_route_404_still_sends_the_security_headers(): void
    {
        $response = $this->get('/this-route-does-not-exist');

        $response->assertNotFound();
        $this->assertSecurityHeaders($response);
    }

    public function test_a_route_model_binding_404_still_sends_the_security_headers(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/servers/01J00000000000000000000000');

        $response->assertNotFound();
        $this->assertSecurityHeaders($response);
    }

    public function test_the_csp_nonce_matches_every_inline_script(): void
    {
        $response = $this->get('/login');
        $nonce = $this->cspNonce($response);
        $html = (string) $response->getContent();

        preg_match_all('/<script\b(?<attributes>[^>]*)>(?<body>.*?)<\/script>/is', $html, $matches, PREG_SET_ORDER);

        $inlineScripts = 0;

        foreach ($matches as $match) {
            $this->assertStringContainsString(
                'nonce="'.$nonce.'"',
                $match['attributes'],
                'A script tag is missing the CSP nonce.',
            );

            if (preg_match('/\bsrc\s*=/i', $match['attributes']) !== 1) {
                $inlineScripts++;
            }
        }

        $this->assertGreaterThanOrEqual(2, $inlineScripts, 'Expected the bootstrap script and the Livewire script config.');
        $this->assertStringContainsString('nonce="'.$nonce.'"', $this->bootstrapScript($html));
        $this->assertStringContainsString('window.livewireScriptConfig', $html);
    }

    public function test_every_inline_script_on_the_dashboard_carries_the_csp_nonce(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/dashboard');
        $nonce = $this->cspNonce($response);
        $html = (string) $response->getContent();

        preg_match_all('/<script\b(?<attributes>[^>]*)>(?<body>.*?)<\/script>/is', $html, $matches, PREG_SET_ORDER);

        $this->assertNotEmpty($matches, 'The dashboard rendered no script tags.');

        $inlineScripts = 0;

        foreach ($matches as $match) {
            $this->assertStringContainsString(
                'nonce="'.$nonce.'"',
                $match['attributes'],
                'A dashboard script tag is missing the CSP nonce.',
            );

            if (preg_match('/\bsrc\s*=/i', $match['attributes']) !== 1) {
                $inlineScripts++;
            }
        }

        $this->assertGreaterThanOrEqual(2, $inlineScripts, 'Expected the bootstrap script and the Livewire script config.');
    }

    public function test_the_livewire_script_config_carries_the_same_nonce_as_the_header(): void
    {
        $response = $this->get('/login');
        $nonce = $this->cspNonce($response);
        $html = (string) $response->getContent();

        $this->assertMatchesRegularExpression(
            '/<script[^>]*nonce="'.preg_quote($nonce, '/').'"[^>]*>\s*window\.livewireScriptConfig/',
            $html,
        );
    }

    public function test_realtime_websocket_origin_is_added_to_connect_src_only_when_enabled(): void
    {
        config([
            'noc.realtime.enabled' => true,
            'reverb.apps.apps.0.options.host' => 'noc.example.com',
            'reverb.apps.apps.0.options.port' => 443,
            'reverb.apps.apps.0.options.scheme' => 'https',
        ]);

        $response = $this->actingAs(User::factory()->create())->get('/dashboard');
        $response->assertOk();

        $this->assertStringContainsString(
            "connect-src 'self' wss://noc.example.com",
            (string) $response->headers->get('Content-Security-Policy'),
        );
    }

    public function test_realtime_websocket_origin_is_absent_when_disabled(): void
    {
        config(['noc.realtime.enabled' => false]);

        $response = $this->actingAs(User::factory()->create())->get('/dashboard');
        $response->assertOk();

        $csp = (string) $response->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("connect-src 'self'", $csp);
        $this->assertStringNotContainsString('wss://', $csp);
    }

    public function test_the_content_security_policy_contains_the_expected_directives(): void
    {
        $response = $this->get('/login');
        $csp = (string) $response->headers->get('Content-Security-Policy');
        $nonce = $this->cspNonce($response);

        foreach ([
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}' 'unsafe-eval'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data:",
            "font-src 'self'",
            "connect-src 'self'",
            "object-src 'none'",
            "frame-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ] as $directive) {
            $this->assertStringContainsString($directive, $csp);
        }
    }

    public function test_hsts_is_absent_outside_production(): void
    {
        $this->app['env'] = 'local';

        $this->assertFalse($this->get('/login')->headers->has('Strict-Transport-Security'));
    }

    public function test_hsts_is_absent_over_http_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->assertFalse($this->get('/login')->headers->has('Strict-Transport-Security'));
    }

    public function test_hsts_is_absent_over_https_outside_production(): void
    {
        $this->app['env'] = 'local';

        $response = $this->get('https://localhost/login');

        $this->assertFalse($response->headers->has('Strict-Transport-Security'));
    }

    public function test_hsts_is_present_in_production_over_https_only(): void
    {
        $this->app['env'] = 'production';

        $response = $this->get('https://localhost/login');

        $response->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    private function assertSecurityHeaders(TestResponse $response): void
    {
        $csp = (string) $response->headers->get('Content-Security-Policy');

        $this->assertNotSame('', $csp);
        $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-[A-Za-z0-9+\/=_-]+' 'unsafe-eval'/", $csp);
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
    }

    private function cspNonce(TestResponse $response): string
    {
        $csp = (string) $response->headers->get('Content-Security-Policy');

        $this->assertMatchesRegularExpression("/'nonce-([A-Za-z0-9+\/=_-]+)'/", $csp);
        preg_match("/'nonce-([A-Za-z0-9+\/=_-]+)'/", $csp, $matches);

        return $matches[1];
    }

    private function bootstrapScript(string $html): string
    {
        preg_match_all('/<script\b(?<attributes>[^>]*)>(?<body>.*?)<\/script>/is', $html, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            if (str_contains($match['body'], 'localStorage.getItem')) {
                return $match[0];
            }
        }

        $this->fail('The bootstrap script is missing.');
    }
}
