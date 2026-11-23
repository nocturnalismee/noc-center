<?php

namespace Tests\Feature\Ingest;

use App\Http\Controllers\Api\IngestController;
use App\Models\MetricSample;
use App\Models\Server;
use App\Models\ServerToken;
use App\Services\Ingest\IngestPayload;
use App\Services\Ingest\PayloadValidator;
use App\Services\Tokens\TokenService;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Throwable;

class PayloadValidationTest extends TestCase
{
    use RefreshDatabase;

    private const URI = '/api/v1/ingest';

    private const MAX_BYTES = 65_536;

    private TokenService $tokens;

    private PayloadValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tokens = $this->app->make(TokenService::class);
        $this->validator = $this->app->make(PayloadValidator::class);

        Carbon::setTestNow('2026-11-22 12:00:00');

        $this->clearRateLimits();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_the_documented_agent_payload_is_accepted(): void
    {
        $response = $this->ingest($this->payload());

        $response->assertStatus(202);
        $response->assertExactJson(['status' => 'accepted']);
        $this->assertNoStore($response);
    }

    public function test_the_payload_maps_to_a_readonly_dto(): void
    {
        $payload = $this->validator->validate($this->payloadJson());

        $this->assertInstanceOf(IngestPayload::class, $payload);
        $this->assertSame(1, $payload->schemaVersion);
        $this->assertSame('11111111-2222-3333-4444-555555555555', $payload->agentId);
        $this->assertSame('web01.example.test', $payload->hostname);
        $this->assertSame('cpanel', $payload->panel);
        $this->assertSame(60, $payload->intervalSeconds);
        $this->assertSame('AlmaLinux', $payload->osName);
        $this->assertSame('9.4', $payload->osVersion);
        $this->assertSame('5.14.0-362.8.1.el9_3.x86_64', $payload->kernel);
        $this->assertSame('0.1.0', $payload->agentVersion);
        $this->assertSame(123456, $payload->uptimeSeconds);
        $this->assertSame(12.5, $payload->metrics['cpu']['usage_pct']);
        $this->assertSame(8, $payload->metrics['cpu']['cores']);
        $this->assertSame(0, $payload->metrics['memory']['total_bytes']);
        $this->assertSame('120.0.1', $payload->panelMetrics['cpanel.version']);
        $this->assertSame(true, $payload->panelMetrics['service.apache.running']);
        $this->assertSame(2, $payload->extensions['smart.disks']);
        $this->assertSame('2026-11-22 12:00:00', $payload->collectedAt->toDateTimeString());
        $this->assertSame('UTC', $payload->collectedAt->timezoneName);
        $this->assertSame('2026-11-22 12:00:00', $payload->receivedAt->toDateTimeString());
    }

    public function test_the_dto_exposes_no_mutable_state(): void
    {
        $payload = $this->validator->validate($this->payloadJson());

        $this->assertTrue((new \ReflectionClass(IngestPayload::class))->isReadOnly());
        $this->assertInstanceOf(Arrayable::class, $payload);

        $this->expectException(\Error::class);

        $payload->hostname = 'mutated';
    }

    public function test_a_body_with_invalid_utf8_is_a_bad_request(): void
    {
        // PHP's JSON decoder rejects invalid UTF-8 outright, so such a body
        // never reaches schema validation: it is malformed JSON (R36), not a
        // 422, and nothing is stored. The validator's own UTF-8 guard remains
        // as defence for values built in PHP rather than decoded.
        $json = str_replace('"smart.status":"ok"', "\"smart.status\":\"\xB1garbage\"", $this->payloadJson());

        $response = $this->ingestRaw($json);

        $response->assertStatus(400);
        $response->assertExactJson(['error' => 'invalid_json']);
        $this->assertNoStore($response);
        $this->assertSame(0, MetricSample::query()->count());
    }

    public function test_unknown_top_level_keys_are_ignored(): void
    {
        $body = $this->payload();
        $body['future_field'] = ['nested' => ['ignored' => true]];
        $body['agent']['diagnostic'] = 'ignored';

        $this->ingest($body)->assertStatus(202);

        $payload = $this->validator->validate($this->payloadJson($body));
        $this->assertSame('web01.example.test', $payload->hostname);
    }

    public function test_malformed_json_is_a_bad_request(): void
    {
        $response = $this->ingestRaw('{"schema_version": 1, "agent": ');

        $response->assertStatus(400);
        $response->assertExactJson(['error' => 'invalid_json']);
        $this->assertNoStore($response);
        $this->assertSame(0, MetricSample::query()->count());
    }

    public function test_a_json_scalar_is_a_bad_request(): void
    {
        foreach (['"schema-v1"', '123', 'true'] as $body) {
            $this->ingestRaw($body)->assertStatus(400)->assertExactJson(['error' => 'invalid_json']);
        }
    }

    public function test_a_json_list_is_a_schema_error(): void
    {
        // A top-level list is well-formed JSON but not a payload object.
        $this->ingestRaw('[{"schema_version":1}]')
            ->assertStatus(400)
            ->assertExactJson(['error' => 'invalid_json']);
    }

    public function test_json_deeper_than_the_limit_is_rejected(): void
    {
        $deep = '{"schema_version":1,"agent":'.str_repeat('{"a":', 8).'1'.str_repeat('}', 8).'}';

        $this->ingestRaw($deep)->assertStatus(400)->assertExactJson(['error' => 'invalid_json']);
    }

    public function test_an_empty_body_is_malformed_json(): void
    {
        // An empty body is not a JSON document at all: it is a transport-level
        // failure (400), consistent with the auth suite's ruling (R32f), not a
        // schema violation. Keep the check explicit so the boundary is pinned.
        $response = $this->ingestRaw('');

        $response->assertStatus(400);
        $response->assertExactJson(['error' => 'invalid_json']);
        $this->assertNoStore($response);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function schemaViolations(): array
    {
        return [
            'missing schema_version' => [['schema_version' => null], 'schema_version'],
            'schema_version 2' => [['schema_version' => 2], 'schema_version'],
            'schema_version string' => [['schema_version' => '2'], 'schema_version'],
            'missing agent' => [['agent' => null], 'agent'],
            'agent not an object' => [['agent' => 'web01'], 'agent'],
            'missing agent id' => [['agent.id' => null], 'agent.id'],
            'agent id too long' => [['agent.id' => Str::repeat('a', 65)], 'agent.id'],
            'missing hostname' => [['agent.hostname' => null], 'agent.hostname'],
            'hostname numeric' => [['agent.hostname' => 42], 'agent.hostname'],
            'hostname too long' => [['agent.hostname' => Str::repeat('h', 254)], 'agent.hostname'],
            'missing panel' => [['agent.panel' => null], 'agent.panel'],
            'panel too long' => [['agent.panel' => Str::repeat('p', 33)], 'agent.panel'],
            'panel leading dash' => [['agent.panel' => '-cpanel'], 'agent.panel'],
            'panel spaces' => [['agent.panel' => 'c panel'], 'agent.panel'],
            'missing collected_at' => [['agent.collected_at' => null], 'agent.collected_at'],
            'collected_at not a date' => [['agent.collected_at' => 'yesterday'], 'agent.collected_at'],
            'missing interval' => [['agent.interval_seconds' => null], 'agent.interval_seconds'],
            'interval below 10' => [['agent.interval_seconds' => 9], 'agent.interval_seconds'],
            'interval above 3600' => [['agent.interval_seconds' => 3601], 'agent.interval_seconds'],
            'missing metrics' => [['metrics' => null], 'metrics'],
            'missing cpu usage' => [['metrics.cpu.usage_pct' => null], 'metrics.cpu.usage_pct'],
            'cpu usage above 100' => [['metrics.cpu.usage_pct' => 100.01], 'metrics.cpu.usage_pct'],
            'cpu usage negative' => [['metrics.cpu.usage_pct' => -1], 'metrics.cpu.usage_pct'],
            'iowait above 100' => [['metrics.cpu.iowait_pct' => 120], 'metrics.cpu.iowait_pct'],
            'load negative' => [['metrics.cpu.load1' => -0.1], 'metrics.cpu.load1'],
            'cores zero' => [['metrics.cpu.cores' => 0], 'metrics.cpu.cores'],
            'cores above 1024' => [['metrics.cpu.cores' => 1025], 'metrics.cpu.cores'],
            'cores float' => [['metrics.cpu.cores' => 8.5], 'metrics.cpu.cores'],
            'mem total negative' => [['metrics.memory.total_bytes' => -1], 'metrics.memory.total_bytes'],
            'mem used pct above 100' => [['metrics.memory.used_pct' => 101], 'metrics.memory.used_pct'],
            'missing swap' => [['metrics.swap' => null], 'metrics.swap'],
            'missing disk root' => [['metrics.disk.root' => null], 'metrics.disk.root'],
            'inode pct above 100' => [['metrics.disk.root.inode_pct' => 100.5], 'metrics.disk.root.inode_pct'],
            'net rx negative' => [['metrics.network.rx_bytes_per_sec' => -2], 'metrics.network.rx_bytes_per_sec'],
            'processes running negative' => [['metrics.processes.running' => -1], 'metrics.processes.running'],
            'missing panel_metrics' => [['panel_metrics' => []], 'panel_metrics'],
            'panel_metrics list' => [['panel_metrics' => ['a', 'b']], 'panel_metrics'],
            'panel_metrics nested array' => [['panel_metrics' => ['cpanel.version' => ['nested']]], 'panel_metrics.cpanel.version'],
            'panel_metrics nested object' => [['panel_metrics' => ['cpanel.version' => ['a' => 1]]], 'panel_metrics.cpanel.version'],
            'panel_metrics bad key' => [['panel_metrics' => ['9lives' => 1]], 'panel_metrics'],
            'panel_metrics key too long' => [['panel_metrics' => ['a'.Str::repeat('b', 64) => 1]], 'panel_metrics'],
            'panel_metrics long string' => [['panel_metrics' => ['cpanel.version' => Str::repeat('v', 1025)]], 'panel_metrics.cpanel.version'],
            'missing extensions' => [['extensions' => []], 'extensions'],
            'extensions scalar' => [['extensions' => 1], 'extensions'],
            'extensions nested array' => [['extensions' => ['smart.disk.sda.realloc' => [1]]], 'extensions.smart.disk.sda.realloc'],
            'system uptime negative' => [['system.uptime_seconds' => -1], 'system.uptime_seconds'],
            'system os name too long' => [['system.os.name' => Str::repeat('o', 129)], 'system.os.name'],
        ];
    }

    /**
     * @param  array<string, mixed>  $replacement
     */
    #[DataProvider('schemaViolations')]
    public function test_schema_violations_are_unprocessable(array $replacement, string $field): void
    {
        $body = $this->payload();

        foreach ($replacement as $dotted => $value) {
            $this->assign($body, $dotted, $value);
        }

        // Forced strings that PHP would coerce are covered by the dedicated
        // test_the_validator_coerces_numeric_strings().

        $response = $this->ingestRaw($this->payloadJson($body));

        $response->assertStatus(422);
        $this->assertNoStore($response);
        $this->assertStringContainsString('"errors":{"'.$field.'"', $response->getContent());
        $this->assertSame(0, MetricSample::query()->count());
    }

    public function test_every_documented_metric_is_required(): void
    {
        $required = [
            'metrics.cpu.usage_pct',
            'metrics.cpu.iowait_pct',
            'metrics.cpu.load1',
            'metrics.cpu.load5',
            'metrics.cpu.load15',
            'metrics.cpu.cores',
            'metrics.memory.total_bytes',
            'metrics.memory.used_bytes',
            'metrics.memory.used_pct',
            'metrics.swap.total_bytes',
            'metrics.swap.used_bytes',
            'metrics.swap.used_pct',
            'metrics.disk.root.total_bytes',
            'metrics.disk.root.used_bytes',
            'metrics.disk.root.used_pct',
            'metrics.disk.root.inode_pct',
            'metrics.network.rx_bytes_per_sec',
            'metrics.network.tx_bytes_per_sec',
            'metrics.processes.running',
            'metrics.processes.blocked',
        ];

        foreach ($required as $dotted) {
            $body = $this->payload();
            $this->forget($body, $dotted);

            $this->ingestRaw($this->payloadJson($body))
                ->assertStatus(422)
                ->assertJsonValidationErrors([$dotted]);
        }

        $body = $this->payload();
        unset($body['system']['uptime_seconds']);

        $this->ingest($body)->assertStatus(202);
        $this->assertNull($this->validator->validate($this->payloadJson($body))->uptimeSeconds);
    }

    public function test_system_details_are_optional(): void
    {
        $body = $this->payload();
        unset($body['system']);

        $this->ingest($body)->assertStatus(202);

        $payload = $this->validator->validate($this->payloadJson($body));
        $this->assertNull($payload->osName);
        $this->assertNull($payload->osVersion);
        $this->assertNull($payload->kernel);

        $body['system'] = ['os' => ['kernel' => '6.1.0']];
        $payload = $this->validator->validate($this->payloadJson($body));
        $this->assertNull($payload->osName);
        $this->assertSame('6.1.0', $payload->kernel);

        unset($body['agent']['version']);
        $this->assertNull($this->validator->validate($this->payloadJson($body))->agentVersion);
    }

    public function test_an_omitted_agent_version_is_accepted(): void
    {
        $body = $this->payload();
        unset($body['agent']['version']);

        $this->ingest($body)->assertStatus(202);
    }

    public function test_empty_flat_maps_are_accepted(): void
    {
        // The agent drops an oversized map and sends `{}`; a PHP empty array
        // would encode as `[]`, which is a shape error (see the list test).
        $json = strtr($this->payloadJson($this->payload()), [
            '"panel_metrics":{"cpanel.version":"120.0.1","service.apache.running":true}' => '"panel_metrics":{}',
            '"extensions":{"smart.status":"ok","smart.disks":2,"smart.cache_age_seconds":86400}' => '"extensions":{}',
        ]);

        $this->ingestRaw($json)->assertStatus(202);

        $payload = $this->validator->validate($json);
        $this->assertSame([], $payload->panelMetrics);
        $this->assertSame([], $payload->extensions);
    }

    public function test_a_flat_map_of_exactly_the_key_cap_is_accepted(): void
    {
        $body = $this->payload();
        $body['panel_metrics'] = $this->flatMap(128);
        $body['extensions'] = $this->flatMap(128);

        $this->ingest($body)->assertStatus(202);

        $payload = $this->validator->validate($this->payloadJson($body));
        $this->assertCount(128, $payload->panelMetrics);
        $this->assertCount(128, $payload->extensions);
    }

    public function test_a_panel_metrics_map_over_the_key_cap_is_rejected(): void
    {
        $body = $this->payload();
        $body['panel_metrics'] = $this->flatMap(129);

        $response = $this->ingestRaw($this->payloadJson($body));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['panel_metrics']);
        $this->assertSame(0, MetricSample::query()->count());
    }

    public function test_an_extensions_map_over_the_key_cap_is_rejected(): void
    {
        $body = $this->payload();
        $body['extensions'] = $this->flatMap(129);

        $response = $this->ingestRaw($this->payloadJson($body));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['extensions']);
        $this->assertSame(0, MetricSample::query()->count());
    }

    public function test_a_five_thousand_key_payload_is_rejected_not_stored(): void
    {
        // 5,000 short keys still fit under 64 KiB, so this is a schema 422 and
        // not a 413: the map is rejected, never truncated.
        $body = $this->payload();
        $body['panel_metrics'] = [];

        for ($index = 0; $index < 5_000; $index++) {
            $body['panel_metrics']['k'.$index] = $index;
        }

        $json = $this->payloadJson($body);
        $this->assertLessThanOrEqual(self::MAX_BYTES, strlen($json));

        $response = $this->ingestRaw($json);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['panel_metrics']);
        $this->assertSame(0, MetricSample::query()->count());
        $this->assertSame(0, $this->detailCount());
    }

    public function test_a_flat_map_string_of_exactly_the_byte_cap_is_accepted(): void
    {
        $body = $this->payload();
        $body['extensions'] = ['smart.detail' => Str::repeat('é', 512)];

        $this->ingest($body)->assertStatus(202);
    }

    public function test_a_scalar_flat_map_value_may_be_null(): void
    {
        $body = $this->payload();
        $body['extensions'] = ['smart.status' => null, 'mysql.threads_running' => 3.5];

        $this->ingest($body)->assertStatus(202);

        $payload = $this->validator->validate($this->payloadJson($body));
        $this->assertNull($payload->extensions['smart.status']);
    }

    public function test_collected_at_in_the_past_is_clamped_to_ten_minutes(): void
    {
        $body = $this->payload();
        $body['agent']['collected_at'] = '2026-11-22T11:30:00Z';

        $this->ingest($body)->assertStatus(202);

        $this->assertSame(
            '2026-11-22 11:50:00',
            MetricSample::query()->sole()->collected_at->toDateTimeString(),
        );
    }

    public function test_collected_at_in_the_future_is_clamped_to_ten_minutes(): void
    {
        $body = $this->payload();
        $body['agent']['collected_at'] = '2026-11-22T12:30:00Z';

        $this->ingest($body)->assertStatus(202);

        $this->assertSame(
            '2026-11-22 12:10:00',
            MetricSample::query()->sole()->collected_at->toDateTimeString(),
        );
    }

    public function test_collected_at_within_the_window_is_kept(): void
    {
        $body = $this->payload();
        $body['agent']['collected_at'] = '2026-11-22T11:54:59Z';

        $this->ingest($body)->assertStatus(202);

        $this->assertSame(
            '2026-11-22 11:54:59',
            MetricSample::query()->sole()->collected_at->toDateTimeString(),
        );
    }

    public function test_a_non_utc_collected_at_is_converted_to_utc(): void
    {
        $body = $this->payload();
        $body['agent']['collected_at'] = '2026-11-22T19:00:00+07:00';

        $this->ingest($body)->assertStatus(202);

        $sample = MetricSample::query()->sole();

        $this->assertSame('2026-11-22 12:00:00', $sample->collected_at->toDateTimeString());
        $this->assertSame('2026-11-22 12:00:00', $sample->received_at->toDateTimeString());
    }

    public function test_an_invalid_collected_at_is_a_schema_error(): void
    {
        $body = $this->payload();
        $body['agent']['collected_at'] = '2026-13-40T99:99:99Z';

        $this->ingestRaw($this->payloadJson($body))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['agent.collected_at']);
    }

    public function test_the_size_boundary_is_inclusive(): void
    {
        $exact = $this->padded(65_536);
        $this->assertSame(65_536, strlen($exact));
        $this->assertSame('}', $exact[strlen($exact) - 1]);

        $this->ingestRaw($exact)->assertStatus(202);
        $this->ingestRaw($this->padded(65_537))
            ->assertStatus(413)
            ->assertExactJson(['error' => 'payload_too_large']);
    }

    /**
     * Build the standard payload with an ignored `padding` key of exactly the
     * requested total size (the deliberate off-by-one on a 65,536-byte
     * literal is why the dollar sign is escaped).
     */
    private function padded(int $size): string
    {
        $prefix = substr($this->payloadJson(), 0, -1).',"padding":"';
        $suffix = '"}';
        $padding = max(0, $size - strlen($prefix) - strlen($suffix));

        return $prefix.Str::repeat('x', $padding).$suffix;
    }

    public function test_the_validator_coerces_numeric_strings(): void
    {
        // Laravel's `integer`/`numeric` rules accept numeric strings; the strict
        // types that matter (identity, maps, cores) are integer-validated. Recorded
        // so nobody mistakes this for strict JSON typing.
        $body = $this->payload();
        $body['agent']['interval_seconds'] = '60';
        $body['metrics']['cpu']['usage_pct'] = '12.5';

        $payload = $this->validator->validate($this->payloadJson($body));

        $this->assertSame(60, $payload->intervalSeconds);
        $this->assertSame('12.5', $payload->metrics['cpu']['usage_pct']);
    }

    public function test_an_unsupported_media_type_reaches_validation(): void
    {
        $response = $this->ingestRaw($this->payloadJson(), 'text/plain');

        $response->assertStatus(415);
        $response->assertExactJson(['error' => 'unsupported_media_type']);
        $this->assertNoStore($response);
    }

    public function test_a_non_json_media_type_is_rejected_even_for_an_empty_body(): void
    {
        $this->ingestRaw('', 'text/plain')
            ->assertStatus(415)
            ->assertExactJson(['error' => 'unsupported_media_type']);
    }

    public function test_a_malformed_json_media_type_parameter_is_rejected(): void
    {
        foreach (['application/json garbage', 'application/jsonx'] as $type) {
            // Accepted by the Controller, but the middleware only rejects
            // non-JSON bodies: it is a 415 before any parsing.
            $this->ingestRaw($this->payloadJson(), $type)
                ->assertStatus(415)
                ->assertExactJson(['error' => 'unsupported_media_type']);
        }

        foreach (['application/json;=utf-8', 'application/json;charset'] as $type) {
            // A parameter without a name or without a value is not a JSON
            // media type; the Controller's stricter parser answers 415 even
            // though the middleware's looser check let it through (R36.1).
            $this->ingestRaw($this->payloadJson(), $type)
                ->assertStatus(415)
                ->assertExactJson(['error' => 'unsupported_media_type']);
        }

        // An empty parameter list still carries the exact `application/json`
        // type and is accepted deterministically by the Controller.
        $this->ingestRaw($this->payloadJson(), 'application/json;;charset=utf-8')
            ->assertStatus(202)
            ->assertExactJson(['status' => 'accepted']);
    }

    public function test_json_media_type_parameters_are_accepted(): void
    {
        // Same media type, parameters, case and optional leading space.
        foreach (['application/json;charset=UTF-8', 'application/json ; charset=utf-8', 'APPLICATION/JSON; charset="utf-8"'] as $type) {
            $this->ingestRaw($this->payloadJson(), $type)
                ->assertStatus(202)
                ->assertExactJson(['status' => 'accepted']);
        }
    }

    public function test_a_negative_content_length_is_a_bad_request(): void
    {
        $response = $this->ingestWithContentLength($this->payloadJson(), '-1');

        $response->assertStatus(400);
        $response->assertExactJson(['error' => 'bad_request']);
        $this->assertNoStore($response);
        $this->assertSame(0, MetricSample::query()->count());
    }

    public function test_a_non_numeric_content_length_is_a_bad_request(): void
    {
        // The middleware maps a malformed header to 0 and lets it through
        // (R36.2); the controller then refuses to treat it as valid.
        $response = $this->ingestWithContentLengthLikelyPassingMiddleware();

        $response->assertStatus(400);
        $response->assertExactJson(['error' => 'bad_request']);
    }

    /**
     * `Content-Length: abc` reaches the controller only when the SAPI did not
     * already turn it into a rejection; in this harness the request is built
     * directly so the controller's own guard is exercised.
     */
    private function ingestWithContentLengthLikelyPassingMiddleware(): TestResponse
    {
        $plain = $this->issueToken();
        $token = $this->app->make(TokenService::class)->resolve($plain);

        $request = Request::create(
            self::URI,
            'POST',
            server: [
                'HTTP_AUTHORIZATION' => 'Bearer '.$plain,
                'CONTENT_TYPE' => 'application/json',
                'CONTENT_LENGTH' => 'abc',
            ],
            content: $this->payloadJson(),
        );

        $request->attributes->set('noc_token', $token);

        return new TestResponse(
            $this->app->make(IngestController::class)($request),
        );
    }

    public function test_an_oversized_content_length_is_rejected_before_validation(): void
    {
        // The middleware rejects the declared size, so this never reaches the
        // controller; the controller would answer the same 413.
        $this->ingestWithContentLength($this->payloadJson(), '65537')
            ->assertStatus(413)
            ->assertExactJson(['error' => 'payload_too_large']);
    }

    public function test_a_declared_size_over_the_limit_wins_over_a_malformed_header(): void
    {
        // A malformed header is a 400 only when the middleware lets it through;
        // an over-limit declared size is still a 413 (the actual body is fine).
        $this->ingestWithContentLength($this->payloadJson(), '-1')
            ->assertStatus(400);

        $this->ingestWithContentLength($this->payloadJson(), ' 65537 ')
            ->assertStatus(413);
    }

    public function test_oversized_payloads_are_not_stored(): void
    {
        $plain = $this->issueToken();

        $this->postBody(Str::repeat('a', self::MAX_BYTES + 1), 'application/json', $plain)
            ->assertStatus(413);

        $this->assertSame(0, MetricSample::query()->count());
    }

    public function test_validation_errors_are_structured_per_field(): void
    {
        $body = $this->payload();
        $body['schema_version'] = 2;
        $body['agent']['interval_seconds'] = 5;
        $body['panel_metrics'] = ['bad key' => 1];

        $response = $this->ingestRaw($this->payloadJson($body));

        $response->assertStatus(422);

        $errors = $response->json('errors');

        $this->assertSame(['schema_version', 'agent.interval_seconds', 'panel_metrics'], array_keys($errors));
        $this->assertSame(['The selected schema version is invalid.'], $errors['schema_version']);
        $this->assertSame(['The agent.interval seconds field must be between 10 and 3600.'], $errors['agent.interval_seconds']);
        $this->assertStringContainsString('bad key', $errors['panel_metrics'][0]);
        $this->assertSame(0, MetricSample::query()->count());
    }

    public function test_the_validator_throws_for_malformed_json(): void
    {
        try {
            $this->validator->validate('{not json');
            $this->fail('Expected the validator to reject malformed JSON.');
        } catch (Throwable $exception) {
            $this->assertInstanceOf(JsonException::class, $exception);
        }
    }

    public function test_the_validator_throws_a_validation_exception_for_schema_errors(): void
    {
        $body = $this->payload();
        $body['schema_version'] = 3;

        try {
            $this->validator->validate($this->payloadJson($body));
            $this->fail('Expected a ValidationException.');
        } catch (Throwable $exception) {
            $this->assertInstanceOf(ValidationException::class, $exception);
        }
    }

    private function issueToken(): string
    {
        $server = Server::query()->create([
            'name' => 'server-'.Str::lower(Str::ulid()),
            'hostname' => 'agent.example.test',
        ]);

        $issued = $this->tokens->issue($server);

        RateLimiter::clear('ingest:token:'.$issued->token->getKey());

        return $issued->plain;
    }

    private function ingest(array $body): TestResponse
    {
        return $this->ingestRaw($this->payloadJson($body));
    }

    private function ingestRaw(string $body, string $contentType = 'application/json'): TestResponse
    {
        return $this->postBody($body, $contentType, $this->issueToken());
    }

    private function postBody(string $body, string $contentType, string $plain): TestResponse
    {
        return $this->call('POST', self::URI, [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$plain,
            'CONTENT_TYPE' => $contentType,
        ], $body);
    }

    private function ingestWithContentLength(string $body, string $contentLength): TestResponse
    {
        return $this->call('POST', self::URI, [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->issueToken(),
            'CONTENT_TYPE' => 'application/json',
            'CONTENT_LENGTH' => $contentLength,
        ], $body);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'schema_version' => 1,
            'agent' => [
                'version' => '0.1.0',
                'id' => '11111111-2222-3333-4444-555555555555',
                'hostname' => 'web01.example.test',
                'panel' => 'cpanel',
                'collected_at' => '2026-11-22T12:00:00Z',
                'interval_seconds' => 60,
            ],
            'system' => [
                'os' => [
                    'name' => 'AlmaLinux',
                    'version' => '9.4',
                    'kernel' => '5.14.0-362.8.1.el9_3.x86_64',
                ],
                'uptime_seconds' => 123456,
            ],
            'metrics' => [
                'cpu' => [
                    'usage_pct' => 12.5,
                    'iowait_pct' => 0.3,
                    'load1' => 0.4,
                    'load5' => 0.3,
                    'load15' => 0.2,
                    'cores' => 8,
                ],
                'memory' => ['total_bytes' => 0, 'used_bytes' => 0, 'used_pct' => 0],
                'swap' => ['total_bytes' => 0, 'used_bytes' => 0, 'used_pct' => 0],
                'disk' => [
                    'root' => [
                        'total_bytes' => 0,
                        'used_bytes' => 0,
                        'used_pct' => 0,
                        'inode_pct' => 0,
                    ],
                ],
                'network' => ['rx_bytes_per_sec' => 0, 'tx_bytes_per_sec' => 0],
                'processes' => ['running' => 3, 'blocked' => 0],
            ],
            'panel_metrics' => [
                'cpanel.version' => '120.0.1',
                'service.apache.running' => true,
            ],
            'extensions' => [
                'smart.status' => 'ok',
                'smart.disks' => 2,
                'smart.cache_age_seconds' => 86400,
            ],
        ];
    }

    private function payloadJson(?array $body = null): string
    {
        return json_encode($body ?? $this->payload(), JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function assign(array &$body, string $dotted, mixed $value): void
    {
        $target = &$body;
        $segments = explode('.', $dotted);
        $last = array_pop($segments);

        foreach ($segments as $segment) {
            $target = &$target[$segment];
        }

        $target[$last] = $value;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function forget(array &$body, string $dotted): void
    {
        $target = &$body;
        $segments = explode('.', $dotted);
        $last = array_pop($segments);

        foreach ($segments as $segment) {
            $target = &$target[$segment];
        }

        unset($target[$last]);
    }

    /**
     * @return array<string, int>
     */
    private function flatMap(int $keys): array
    {
        $map = [];

        for ($index = 0; $index < $keys; $index++) {
            $map['metric'.$index] = $index;
        }

        return $map;
    }

    private function detailCount(): int
    {
        return (int) DB::table('metric_details')->count();
    }

    private function assertNoStore(TestResponse $response, string $context = ''): void
    {
        $this->assertStringContainsString(
            'no-store',
            (string) $response->headers->get('Cache-Control'),
            'Expected a no-store response. '.$context,
        );
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
