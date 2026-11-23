<?php

namespace Tests\Feature\Ingest;

use App\Models\Server;
use App\Models\ServerToken;
use App\Services\Tokens\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mockery\MethodCall;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
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
        $this->assertNoStore($response);
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

        $this->assertRetryAfterBounded($this->ingest('Bearer '.$plain));
    }

    public function test_the_sixty_first_failed_attempt_from_one_ip_is_rate_limited(): void
    {
        $unknown = 'Bearer noc_'.Str::repeat('b', 43);

        for ($attempt = 1; $attempt <= 60; $attempt++) {
            $this->ingest($unknown)
                ->assertStatus(401)
                ->assertExactJson(['error' => 'unauthorized']);
        }

        $this->assertRetryAfterBounded($this->ingest($unknown));
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

    public function test_error_logs_never_contain_the_full_token_or_its_hash(): void
    {
        Log::spy();

        $plain = 'noc_'.Str::repeat('d', 43);
        $unknown = 'noc_'.Str::repeat('e', 43);

        $inactivePlain = $this->issueToken($this->server(['is_active' => false]));
        $softDeleted = $this->server();
        $softDeletedPlain = $this->issueToken($softDeleted);
        $softDeleted->delete();

        $sensitive = [
            'plain' => $plain,
            'unknown' => $unknown,
            'inactive' => $inactivePlain,
            'soft_deleted' => $softDeletedPlain,
            'plain_hash' => hash('sha256', $plain),
            'unknown_hash' => hash('sha256', $unknown),
            'inactive_hash' => hash('sha256', $inactivePlain),
            'soft_deleted_hash' => hash('sha256', $softDeletedPlain),
        ];

        $this->ingest('Bearer '.$plain)->assertStatus(401);
        $this->ingest('Bearer '.$unknown)->assertStatus(401);
        $this->ingest('Bearer '.$inactivePlain)->assertStatus(403);
        $this->ingest('Bearer '.$softDeletedPlain)->assertStatus(401);

        $log = $this->capturedLog();

        $this->assertStringContainsString('Ingest authentication failed.', $log);
        $this->assertStringContainsString(substr($sensitive['inactive'], 0, 8), $log);

        foreach (array_keys($sensitive) as $key) {
            $this->assertStringNotContainsString(
                $sensitive[$key],
                $log,
                "Log output leaked the {$key} value.",
            );
        }
    }

    public function test_logged_token_prefix_strips_control_bytes(): void
    {
        Log::spy();

        // `\S` matches a raw ESC/NUL, so this malformed token reaches the
        // logging branch intact: the log must not carry any of those bytes.
        $malformed = 'noc_ab'."\e[31m".Str::repeat('x', 40);

        $this->ingest('Bearer '.$malformed)->assertStatus(401);

        $log = $this->capturedLog();

        // Eight characters of the allowed alphabet, the rest replaced.
        $this->assertStringContainsString('"token_prefix":"noc_ab.."', $log);
        $this->assertSame(1, preg_match('/^[^\x00-\x1F\x7F]*$/u', $log));
    }

    public function test_the_retry_after_header_is_bounded_on_the_per_token_limit(): void
    {
        $server = $this->server();
        $plain = $this->issueToken($server);

        for ($request = 1; $request <= 30; $request++) {
            $this->ingest('Bearer '.$plain)->assertStatus(202);
        }

        $this->assertRetryAfterBounded($this->ingest('Bearer '.$plain));
    }

    public function test_the_retry_after_header_is_bounded_on_the_failed_ip_limit(): void
    {
        $unknown = 'Bearer noc_'.Str::repeat('f', 43);

        for ($attempt = 1; $attempt <= 60; $attempt++) {
            $this->ingest($unknown)->assertStatus(401);
        }

        $this->assertRetryAfterBounded($this->ingest($unknown));
    }

    public function test_the_retry_after_header_is_bounded_on_the_global_limit(): void
    {
        config(['ingest.global_per_minute' => 1]);

        $plain = $this->issueToken($this->server());

        $this->ingest('Bearer '.$plain)->assertStatus(202);

        $this->assertRetryAfterBounded($this->ingest('Bearer '.$plain));
    }

    public function test_the_global_ceiling_rejects_requests_over_the_limit(): void
    {
        config(['ingest.global_per_minute' => 2]);

        $plain = $this->issueToken($this->server());

        $this->ingest('Bearer '.$plain)->assertStatus(202);
        $this->ingest('Bearer '.$plain)->assertStatus(202);

        $response = $this->ingest('Bearer '.$plain);

        $response->assertStatus(429)->assertExactJson(['error' => 'rate_limited']);
        $this->assertNoStore($response);
        $this->assertRetryAfterBounded($response);
    }

    public function test_the_global_ceiling_spans_tokens(): void
    {
        config(['ingest.global_per_minute' => 1]);

        $first = $this->issueToken($this->server());
        $second = $this->issueToken($this->server());

        $this->ingest('Bearer '.$first)->assertStatus(202);

        $this->ingest('Bearer '.$second)
            ->assertStatus(429)
            ->assertExactJson(['error' => 'rate_limited']);
    }

    public function test_an_inactive_token_cannot_issue_unlimited_forbidden_responses(): void
    {
        $server = $this->server(['is_active' => false]);
        $plain = $this->issueToken($server);

        for ($request = 1; $request <= 30; $request++) {
            $this->ingest('Bearer '.$plain)
                ->assertStatus(403)
                ->assertExactJson(['error' => 'inactive_server']);
        }

        $response = $this->ingest('Bearer '.$plain);

        $response->assertStatus(429)->assertExactJson(['error' => 'rate_limited']);
        $this->assertRetryAfterBounded($response);
    }

    public function test_an_inactive_token_feeds_the_failed_ip_budget(): void
    {
        // Seed the IP just under its configured budget (60) so only this
        // request can trip it: a single 403 must be enough to reach 60.
        $failures = 'ingest:fail:127.0.0.1';

        for ($attempt = 1; $attempt <= 59; $attempt++) {
            RateLimiter::hit($failures, 60);
        }

        $plain = $this->issueToken($this->server(['is_active' => false]));

        $this->assertSame(59, RateLimiter::attempts($failures));

        $response = $this->ingest('Bearer '.$plain);

        $response->assertStatus(429)->assertExactJson(['error' => 'rate_limited']);
        $this->assertRetryAfterBounded($response);
    }

    public function test_a_valid_but_inactive_token_does_not_bypass_the_per_token_bucket(): void
    {
        // Seed the token's own bucket right up to its budget: the inactive
        // server's next request must be throttled instead of answered 403.
        $server = $this->server(['is_active' => false]);
        $plain = $this->issueToken($server);

        $token = ServerToken::query()->latest('id')->firstOrFail();
        $bucket = 'ingest:token:'.$token->getKey();

        for ($request = 1; $request <= 30; $request++) {
            RateLimiter::hit($bucket, 60);
        }

        $response = $this->ingest('Bearer '.$plain);

        $response->assertStatus(429)->assertExactJson(['error' => 'rate_limited']);
        $this->assertRetryAfterBounded($response);
    }

    public function test_two_tokens_keep_separate_buckets(): void
    {
        $plainA = $this->issueToken($this->server());
        $plainB = $this->issueToken($this->server());

        for ($request = 1; $request <= 30; $request++) {
            $this->ingest('Bearer '.$plainA)->assertStatus(202);
        }

        $this->ingest('Bearer '.$plainA)->assertStatus(429);

        $this->ingest('Bearer '.$plainB)
            ->assertStatus(202)
            ->assertExactJson(['status' => 'accepted']);
    }

    public function test_a_malformed_token_issues_no_database_query(): void
    {
        try {
            DB::flushQueryLog();
            DB::enableQueryLog();

            $this->ingest('Bearer noc_'.Str::repeat('g', 42)."\n")
                ->assertStatus(401);

            $this->assertCount(0, DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }
    }

    public function test_an_uppercase_json_content_type_with_charset_is_accepted(): void
    {
        $plain = $this->issueToken($this->server());

        $this->ingest('Bearer '.$plain, '{"schema_version":1}', 'APPLICATION/JSON; charset=utf-8')
            ->assertStatus(202)
            ->assertExactJson(['status' => 'accepted']);
    }

    public function test_a_json_patch_media_type_is_unsupported(): void
    {
        $plain = $this->issueToken($this->server());

        $this->ingest('Bearer '.$plain, '{"schema_version":1}', 'application/json-patch+json')
            ->assertStatus(415)
            ->assertExactJson(['error' => 'unsupported_media_type']);

        // An empty body with a non-JSON type stays pass-through (R32f).
        $this->ingest('Bearer '.$plain, '', 'text/plain')->assertStatus(202);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function nonPostMethods(): array
    {
        return [
            'put' => ['PUT'],
            'patch' => ['PATCH'],
            'delete' => ['DELETE'],
            'head' => ['HEAD'],
        ];
    }

    #[DataProvider('nonPostMethods')]
    public function test_methods_other_than_post_are_not_allowed(string $method): void
    {
        $this->call($method, self::URI)->assertStatus(405);
    }

    public function test_a_lying_content_length_header_is_rejected(): void
    {
        $plain = $this->issueToken($this->server());

        $this->ingestWithContentLength(
            'Bearer '.$plain,
            Str::repeat('a', self::MAX_BYTES + 1),
            10,
        )
            ->assertStatus(413)
            ->assertExactJson(['error' => 'payload_too_large']);
    }

    public function test_every_error_response_is_not_cacheable(): void
    {
        $plain = $this->issueToken($this->server());
        $inactive = $this->issueToken($this->server(['is_active' => false]));

        $errors = [
            'unauthorized' => $this->ingest(null),
            'inactive_server' => $this->ingest('Bearer '.$inactive),
            'payload_too_large' => $this->ingest('Bearer '.$plain, Str::repeat('a', self::MAX_BYTES + 1)),
            'unsupported_media_type' => $this->ingest('Bearer '.$plain, '{"a":1}', 'text/plain'),
            'rate_limited' => $this->rateLimitedResponse($plain),
        ];

        foreach ($errors as $error => $response) {
            $this->assertNoStore($response, $error);
        }
    }

    private function rateLimitedResponse(string $plain): TestResponse
    {
        // The previous error responses already spent part of the failure
        // budget; clear it so the per-token bucket is the one that trips.
        RateLimiter::clear('ingest:fail:127.0.0.1');

        for ($request = 1; $request <= 30; $request++) {
            $this->ingest('Bearer '.$plain)->assertStatus(202);
        }

        return $this->ingest('Bearer '.$plain);
    }

    /**
     * The session middleware appends `private` to `Cache-Control` on every
     * response; `no-store` must still be one of the directives.
     */
    private function assertNoStore(TestResponse $response, string $context = ''): void
    {
        $directives = array_map(
            'trim',
            explode(',', strtolower((string) $response->headers->get('Cache-Control'))),
        );

        $this->assertContains('no-store', $directives, $context);
    }

    private function assertRetryAfterBounded(TestResponse $response): void
    {
        $response->assertStatus(429);

        $retryAfter = $response->headers->get('Retry-After');

        $this->assertNotNull($retryAfter);
        $this->assertMatchesRegularExpression('/^\d+$/', (string) $retryAfter);
        $this->assertGreaterThanOrEqual(1, (int) $retryAfter);
        $this->assertLessThanOrEqual(60, (int) $retryAfter);
    }

    /**
     * Serialize every log call the spied logger received, so assertions run
     * against all of them rather than only the call that matches a pattern.
     */
    private function capturedLog(): string
    {
        $logger = Log::getFacadeRoot();

        $this->assertInstanceOf(MockInterface::class, $logger, 'Call Log::spy() before reading captured log calls.');

        $properties = (array) $logger;

        $calls = (function () {
            return (array) (fn () => $this->methodCalls)->call($this);
        })->call($properties["\0*\0_mockery_receivedMethodCalls"]);

        $this->assertNotSame([], $calls, 'Expected at least one log call.');

        return implode("\n", array_map(
            static fn (MethodCall $call): string => (string) json_encode($call->getArgs()),
            $calls,
        ));
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
