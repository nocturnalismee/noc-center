<?php

namespace Tests\Feature\Ingest;

use App\Models\Server;
use App\Models\ServerToken;
use App\Services\Tokens\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class IngestAuthTest extends TestCase
{
    use RefreshDatabase;

    private const URI = '/api/v1/ingest';

    private const MAX_BYTES = 65_536;

    private TokenService $tokens;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tokens = $this->app->make(TokenService::class);

        $this->clearRateLimits();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_a_valid_token_is_accepted_without_a_stored_response(): void
    {
        $server = $this->server();
        $plain = $this->issueToken($server);

        $response = $this->ingest('Bearer '.$plain);

        $response->assertStatus(202);
        $response->assertExactJson(['status' => 'accepted']);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));
    }

    public function test_a_missing_authorization_header_is_unauthorized(): void
    {
        $this->ingest(null)
            ->assertStatus(401)
            ->assertExactJson(['error' => 'unauthorized']);
    }

    public function test_a_basic_authorization_scheme_is_unauthorized(): void
    {
        $this->ingest('Basic '.base64_encode('agent:secret'))
            ->assertStatus(401)
            ->assertExactJson(['error' => 'unauthorized']);
    }

    public function test_malformed_tokens_are_unauthorized(): void
    {
        $malformed = [
            'noc_'.Str::repeat('a', 42),
            'NOC_'.Str::repeat('a', 43),
            'noc_'.Str::repeat('a', 42).'+',
            'noc_'.Str::repeat('a', 42).'=',
            'noc_'.Str::repeat('a', 42).'/',
        ];

        foreach ($malformed as $plain) {
            $this->ingest('Bearer '.$plain)
                ->assertStatus(401)
                ->assertExactJson(['error' => 'unauthorized']);
        }
    }

    public function test_an_unknown_token_is_unauthorized(): void
    {
        $this->ingest('Bearer noc_'.Str::repeat('a', 43))
            ->assertStatus(401)
            ->assertExactJson(['error' => 'unauthorized']);
    }

    public function test_a_revoked_token_is_unauthorized(): void
    {
        $server = $this->server();
        $plain = $this->issueToken($server, revoke: true);

        $this->ingest('Bearer '.$plain)
            ->assertStatus(401)
            ->assertExactJson(['error' => 'unauthorized']);
    }

    public function test_an_expired_token_is_unauthorized(): void
    {
        Carbon::setTestNow('2026-11-22 12:00:00');

        $server = $this->server();
        $plain = $this->issueToken($server, expiresAt: now()->subMinute());

        $this->ingest('Bearer '.$plain)
            ->assertStatus(401)
            ->assertExactJson(['error' => 'unauthorized']);
    }

    public function test_a_token_for_an_inactive_server_is_forbidden(): void
    {
        $server = $this->server(['is_active' => false]);
        $plain = $this->issueToken($server);

        $this->ingest('Bearer '.$plain)
            ->assertStatus(403)
            ->assertExactJson(['error' => 'inactive_server']);
    }

    public function test_a_token_for_a_soft_deleted_server_is_unauthorized(): void
    {
        $server = $this->server();
        $plain = $this->issueToken($server);

        $server->delete();

        $this->ingest('Bearer '.$plain)
            ->assertStatus(401)
            ->assertExactJson(['error' => 'unauthorized']);
    }

    public function test_get_requests_are_not_allowed(): void
    {
        $this->get(self::URI)->assertStatus(405);
    }

    public function test_a_body_over_the_maximum_size_is_rejected(): void
    {
        $server = $this->server();
        $plain = $this->issueToken($server);

        $this->ingest('Bearer '.$plain, Str::repeat('a', self::MAX_BYTES + 1))
            ->assertStatus(413)
            ->assertExactJson(['error' => 'payload_too_large']);
    }

    public function test_an_empty_body_with_an_oversized_content_length_is_rejected(): void
    {
        $server = $this->server();
        $plain = $this->issueToken($server);

        $this->ingestWithContentLength(
            'Bearer '.$plain,
            '',
            self::MAX_BYTES + 1,
        )
            ->assertStatus(413)
            ->assertExactJson(['error' => 'payload_too_large']);
    }

    public function test_a_body_of_exactly_the_maximum_size_reaches_the_controller(): void
    {
        $server = $this->server();
        $plain = $this->issueToken($server);

        $this->ingest('Bearer '.$plain, Str::repeat(' ', self::MAX_BYTES))
            ->assertStatus(202)
            ->assertExactJson(['status' => 'accepted']);
    }

    public function test_a_non_json_content_type_is_unsupported(): void
    {
        $server = $this->server();
        $plain = $this->issueToken($server);

        $this->ingest('Bearer '.$plain, '{"schema_version":1}', 'text/plain')
            ->assertStatus(415)
            ->assertExactJson(['error' => 'unsupported_media_type']);
    }

    public function test_a_json_content_type_with_parameters_is_accepted(): void
    {
        $server = $this->server();
        $plain = $this->issueToken($server);

        $this->ingest('Bearer '.$plain, '{"schema_version":1}', 'application/json;charset=UTF-8')
            ->assertStatus(202)
            ->assertExactJson(['status' => 'accepted']);
    }

    public function test_the_thirty_first_request_in_a_minute_for_one_token_is_rate_limited(): void
    {
        $server = $this->server();
        $plain = $this->issueToken($server);

        for ($request = 1; $request <= 30; $request++) {
            $this->ingest('Bearer '.$plain)
                ->assertStatus(202)
                ->assertExactJson(['status' => 'accepted']);
        }

        $response = $this->ingest('Bearer '.$plain);

        $response->assertStatus(429);
        $response->assertExactJson(['error' => 'rate_limited']);
        $this->assertNotNull($response->headers->get('Retry-After'));
        $this->assertGreaterThan(0, (int) $response->headers->get('Retry-After'));
    }

    public function test_the_sixty_first_failed_attempt_from_one_ip_is_rate_limited(): void
    {
        $unknown = 'Bearer noc_'.Str::repeat('b', 43);

        for ($attempt = 1; $attempt <= 60; $attempt++) {
            $this->ingest($unknown)
                ->assertStatus(401)
                ->assertExactJson(['error' => 'unauthorized']);
        }

        $response = $this->ingest($unknown);

        $response->assertStatus(429);
        $response->assertExactJson(['error' => 'rate_limited']);
        $this->assertNotNull($response->headers->get('Retry-After'));
        $this->assertGreaterThan(0, (int) $response->headers->get('Retry-After'));
    }

    public function test_logs_never_contain_the_full_token(): void
    {
        Log::spy();

        $plain = 'noc_'.Str::repeat('c', 43);
        $prefix = substr($plain, 0, 8);

        $this->ingest('Bearer '.$plain)
            ->assertStatus(401)
            ->assertExactJson(['error' => 'unauthorized']);

        Log::shouldHaveReceived('warning')
            ->withArgs(function (mixed $message, array $context = []) use ($plain, $prefix): bool {
                $serialized = (string) $message.' '.json_encode($context);

                $this->assertStringNotContainsString($plain, $serialized);

                return str_contains($serialized, $prefix);
            })
            ->once();
    }

    private function server(array $attributes = []): Server
    {
        return Server::query()->create(array_merge([
            'name' => 'server-'.Str::lower(Str::ulid()),
            'hostname' => 'agent.example.test',
        ], $attributes));
    }

    private function issueToken(
        Server $server,
        bool $revoke = false,
        ?Carbon $expiresAt = null,
    ): string {
        $issued = $this->tokens->issue($server, expiresAt: $expiresAt);

        RateLimiter::clear('ingest:token:'.$issued->token->getKey());

        if ($revoke) {
            $this->tokens->revoke($issued->token);
        }

        return $issued->plain;
    }

    private function ingest(
        ?string $authorization,
        string $body = '{"schema_version":1}',
        string $contentType = 'application/json',
    ): TestResponse {
        $server = [];

        if ($authorization !== null) {
            $server['HTTP_AUTHORIZATION'] = $authorization;
        }

        $server['CONTENT_TYPE'] = $contentType;

        return $this->call('POST', self::URI, [], [], [], $server, $body);
    }

    private function ingestWithContentLength(
        string $authorization,
        string $body,
        int $contentLength,
    ): TestResponse {
        return $this->call('POST', self::URI, [], [], [], [
            'HTTP_AUTHORIZATION' => $authorization,
            'CONTENT_TYPE' => 'application/json',
            'CONTENT_LENGTH' => (string) $contentLength,
        ], $body);
    }

    private function clearRateLimits(): void
    {
        RateLimiter::clear('ingest:global');
        RateLimiter::clear('ingest:fail:127.0.0.1');

        ServerToken::query()->pluck('id')->each(
            static fn (string $id) => RateLimiter::clear('ingest:token:'.$id),
        );
    }
}
