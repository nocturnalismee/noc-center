<?php

namespace App\Services\Ingest;

use App\Rules\JsonInteger;
use App\Rules\JsonNumber;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use JsonException;
use stdClass;

/**
 * Validate a raw schema-v1 agent payload.
 *
 * Failures are split by cause:
 *
 * - malformed JSON, a JSON scalar/list at the root, or nesting deeper than the
 *   protocol allows is a {@see JsonException} (the controller answers 400);
 * - a well-formed payload of the wrong shape, wrong types, out-of-range
 *   numbers, over-long strings, or flat maps past their key/byte caps is a
 *   {@see ValidationException} (422) carrying one structured error per field.
 *
 * The payload is only ever decoded with `json_decode()`, never evaluated.
 */
class PayloadValidator
{
    /**
     * RFC 3339 with a mandatory offset: `Z`/`z` or `±hh:mm`. RFC 3339 is
     * case-insensitive for `T` and `Z`, so both cases are accepted.
     */
    public const COLLECTED_AT_PATTERN = '/\A\d{4}-\d{2}-\d{2}[Tt]\d{2}:\d{2}:\d{2}(?:\.\d{1,9})?(?:[Zz]|[+-]\d{2}:\d{2})\z/';

    /**
     * Keys of `panel_metrics` and `extensions` (spec §8): a letter, then up to
     * 63 further letters, digits, dots, underscores or hyphens.
     */
    public const FLAT_MAP_KEY_PATTERN = '/\A[A-Za-z][A-Za-z0-9_.-]{0,63}\z/';

    /**
     * What a validation message calls each field. Laravel splits hyphenated
     * attribute names (`agent.interval_seconds` → "agent.interval seconds"),
     * so the readable name is supplied explicitly; keys stay dotted for
     * machine-readable errors. All other names are left as the dotted path.
     *
     * @var array<string, string>
     */
    private const ATTRIBUTE_NAMES = [
        'schema_version' => 'schema_version',
        'agent.id' => 'agent.id',
        'agent.hostname' => 'agent_hostname',
        'agent.panel' => 'agent_panel',
        'agent.version' => 'agent_version',
        'agent.collected_at' => 'collected_at',
        'agent.interval_seconds' => 'interval_seconds',
        'system.os.name' => 'os_name',
        'system.os.version' => 'os_version',
        'system.os.kernel' => 'kernel',
        'system.uptime_seconds' => 'uptime_seconds',
        'metrics.cpu.usage_pct' => 'cpu_usage_pct',
        'metrics.cpu.iowait_pct' => 'cpu_iowait_pct',
        'metrics.cpu.load1' => 'load1',
        'metrics.cpu.load5' => 'load5',
        'metrics.cpu.load15' => 'load15',
        'metrics.cpu.cores' => 'cpu_cores',
        'metrics.memory.total_bytes' => 'memory_total_bytes',
        'metrics.memory.used_bytes' => 'memory_used_bytes',
        'metrics.memory.used_pct' => 'memory_used_pct',
        'metrics.swap.total_bytes' => 'swap_total_bytes',
        'metrics.swap.used_bytes' => 'swap_used_bytes',
        'metrics.swap.used_pct' => 'swap_used_pct',
        'metrics.disk.root.total_bytes' => 'disk_total_bytes',
        'metrics.disk.root.used_bytes' => 'disk_used_bytes',
        'metrics.disk.root.used_pct' => 'disk_used_pct',
        'metrics.disk.root.inode_pct' => 'disk_inode_pct',
        'metrics.network.rx_bytes_per_sec' => 'network_rx_bytes_per_sec',
        'metrics.network.tx_bytes_per_sec' => 'network_tx_bytes_per_sec',
        'metrics.processes.running' => 'processes_running',
        'metrics.processes.blocked' => 'processes_blocked',
    ];

    public const PANEL_PATTERN = '/\A[A-Za-z0-9][A-Za-z0-9_.-]*\z/';

    /**
     * A reported collection time further than this from the dashboard clock is
     * clamped to the edge of the window (spec §8 step 5).
     */
    public const CLOCK_SKEW_SECONDS = 600;

    /**
     * The agent may drop both maps when the payload would exceed 64 KiB, so
     * they are optional — but, when present, they must be flat maps.
     */
    private const FLAT_MAPS = ['panel_metrics', 'extensions'];

    /**
     * Payload positions that must be JSON objects, checked for the empty-list
     * ambiguity and for lists in general. The per-key flat-map rules run
     * separately in {@see flatMapFailures()}.
     */
    private const OBJECT_POSITIONS = [
        'agent', 'system', 'system.os', 'metrics', 'metrics.cpu', 'metrics.memory',
        'metrics.swap', 'metrics.disk', 'metrics.disk.root', 'metrics.network',
        'metrics.processes', 'panel_metrics', 'extensions',
    ];

    /**
     * Spec §8: the deepest allowed document is depth 8. PHP's `json_decode()`
     * `$depth` argument is the *maximum* level that may be decoded, so it is
     * passed as `MAX_JSON_DEPTH + 1`: depth 8 decodes, depth 9 throws.
     */
    private const MAX_JSON_DEPTH = 8;

    /**
     * Spec §8 (R3/R23): 128 keys per flat map, 1,024 bytes per string value.
     */
    private const FLAT_MAP_MAX_KEYS = 128;

    private const FLAT_MAP_MAX_STRING_BYTES = 1_024;

    /**
     * @throws JsonException when the body is not a JSON object within the depth limit
     * @throws ValidationException when the payload does not match schema v1
     */
    public function validate(string $rawJson): IngestPayload
    {
        $decoded = $this->decode($rawJson);
        $data = $this->toArray($decoded);

        // A JSON list where an object belongs must be reported before Laravel
        // sees it: a list under an `array` rule can raise a "Syntax error"
        // from the rule parser instead of a field message.
        $failures = $this->listFailures($decoded, $data);

        if ($failures !== []) {
            throw ValidationException::withMessages($failures);
        }

        $validator = $this->validator($data);

        // Shape checks PHP's array rules cannot express: a key pattern, a
        // per-key byte limit, the hard 128-key cap, and flat-map keys that
        // must not echo client bytes. They merge into the same message bag.
        $failures = $this->flatMapFailures($decoded, $data);

        $validator->after(static function (ValidatorContract $validator) use ($failures): void {
            if ($failures !== []) {
                $validator->errors()->merge($failures);
            }
        });

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $this->toPayload($data);
    }

    /**
     * Decode as objects first, then layer into arrays.
     *
     * `json_decode(..., true)` cannot tell `{}` from `[]` (PHP turns both into
     * an empty array), which would make an agent's empty `panel_metrics` look
     * like a JSON list. Decoding objects first preserves that distinction, so
     * `{}` stays an empty `stdClass` and `[]` stays an array.
     *
     * @throws JsonException
     */
    private function decode(string $rawJson): stdClass
    {
        // Depth is enforced by `json_decode()`; see `MAX_JSON_DEPTH` for why the
        // argument is one past the allowed depth.
        $data = json_decode($rawJson, false, self::MAX_JSON_DEPTH + 1, JSON_THROW_ON_ERROR);

        if (! is_object($data)) {
            throw new JsonException('The ingest payload must be a JSON object.');
        }

        return $data;
    }

    /**
     * Convert the decoded tree layer by layer: every object becomes a map and
     * every list stays a list, so validators see the shapes the agent sent.
     *
     * Scalars are returned unchanged, never coerced to `[]`. Pure objects
     * (`{}`) become empty maps; an empty list (`[]`) also becomes an empty map
     * at this point and is rejected on its own by {@see emptyListFailures()}.
     * Non-empty lists stay lists, which the `array` rules already reject where
     * an object was expected.
     */
    private function toArray(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $value = (array) $value;
        }

        if (! is_array($value)) {
            return $value;
        }

        $converted = [];

        foreach ($value as $key => $entry) {
            $converted[$key] = $this->toArray($entry);
        }

        return $converted;
    }

    private function validator(array $data): ValidatorContract
    {
        $validator = Validator::make($data, [
            'schema_version' => ['required', new JsonInteger, 'in:1'],
            'agent' => ['required', 'array'],
            'agent.id' => ['required', 'string', 'max:64'],
            'agent.hostname' => ['required', 'string', 'max:253'],
            'agent.panel' => ['required', 'string', 'max:32', 'regex:'.self::PANEL_PATTERN],
            'agent.version' => ['sometimes', 'nullable', 'string', 'max:32'],
            'agent.collected_at' => ['required', 'string', 'date', 'regex:'.self::COLLECTED_AT_PATTERN],
            'agent.interval_seconds' => ['required', 'integer', new JsonInteger, 'between:10,3600'],

            'system' => ['sometimes', 'nullable', 'array'],
            'system.os' => ['sometimes', 'nullable', 'array'],
            'system.os.name' => ['sometimes', 'nullable', 'string', 'max:128'],
            'system.os.version' => ['sometimes', 'nullable', 'string', 'max:128'],
            'system.os.kernel' => ['sometimes', 'nullable', 'string', 'max:128'],
            // Agent-only and optional: `--dry-run` and older agents may omit it.
            'system.uptime_seconds' => ['sometimes', 'nullable', 'integer', new JsonInteger, 'min:0'],

            'metrics' => ['required', 'array'],
            'metrics.cpu' => ['required', 'array'],
            // Real JSON numbers only: `numeric` would accept `"12.5"` and store 0.
            'metrics.cpu.usage_pct' => ['required', 'numeric', new JsonNumber, 'between:0,100'],
            'metrics.cpu.iowait_pct' => ['required', 'numeric', new JsonNumber, 'between:0,100'],
            'metrics.cpu.load1' => ['required', 'numeric', new JsonNumber, 'min:0'],
            'metrics.cpu.load5' => ['required', 'numeric', new JsonNumber, 'min:0'],
            'metrics.cpu.load15' => ['required', 'numeric', new JsonNumber, 'min:0'],
            'metrics.cpu.cores' => ['required', 'integer', new JsonInteger, 'between:1,1024'],
            'metrics.memory' => ['required', 'array'],
            'metrics.memory.total_bytes' => ['required', 'integer', new JsonInteger, 'min:0'],
            'metrics.memory.used_bytes' => ['required', 'integer', new JsonInteger, 'min:0'],
            'metrics.memory.used_pct' => ['required', 'numeric', new JsonNumber, 'between:0,100'],
            'metrics.swap' => ['required', 'array'],
            'metrics.swap.total_bytes' => ['required', 'integer', new JsonInteger, 'min:0'],
            'metrics.swap.used_bytes' => ['required', 'integer', new JsonInteger, 'min:0'],
            'metrics.swap.used_pct' => ['required', 'numeric', new JsonNumber, 'between:0,100'],
            'metrics.disk' => ['required', 'array'],
            'metrics.disk.root' => ['required', 'array'],
            'metrics.disk.root.total_bytes' => ['required', 'integer', new JsonInteger, 'min:0'],
            'metrics.disk.root.used_bytes' => ['required', 'integer', new JsonInteger, 'min:0'],
            'metrics.disk.root.used_pct' => ['required', 'numeric', new JsonNumber, 'between:0,100'],
            'metrics.disk.root.inode_pct' => ['required', 'numeric', new JsonNumber, 'between:0,100'],
            'metrics.network' => ['required', 'array'],
            'metrics.network.rx_bytes_per_sec' => ['required', 'numeric', new JsonNumber, 'min:0'],
            'metrics.network.tx_bytes_per_sec' => ['required', 'numeric', new JsonNumber, 'min:0'],
            'metrics.processes' => ['required', 'array'],
            'metrics.processes.running' => ['required', 'integer', new JsonInteger, 'min:0'],
            'metrics.processes.blocked' => ['required', 'integer', new JsonInteger, 'min:0'],

            'panel_metrics' => ['sometimes', 'nullable', 'array'],
            'extensions' => ['sometimes', 'nullable', 'array'],
        ]);

        // Readable names keep the field keys dotted but drop the hyphen split
        // that would render `interval_seconds` as "interval seconds".
        $validator->setAttributeNames(self::ATTRIBUTE_NAMES);

        return $validator;
    }

    /**
     * Checks for the flat maps: PHP's `array` rule cannot express a key
     * pattern, a per-key byte limit or the distinct "too many keys" rule, and
     * a silently truncated map would hide data from the operator (R3/R23).
     *
     * @return array<string, list<string>>
     */
    private function flatMapFailures(stdClass $decoded, array $data): array
    {
        $failures = [];

        foreach (self::FLAT_MAPS as $map) {
            if (! property_exists($decoded, $map) || $decoded->{$map} === null) {
                continue;
            }

            $value = Arr::get($data, $map);

            // An empty list or a non-empty list is not a JSON object.
            if (! is_array($value) || $this->isList($decoded->{$map})) {
                $failures[$map][] = 'The '.$map.' field must be a JSON object.';

                continue;
            }

            if (count($value) > self::FLAT_MAP_MAX_KEYS) {
                // Reject, never truncate: the agent drops the map on oversize.
                $failures[$map][] = 'The '.$map.' field must not have more than '.self::FLAT_MAP_MAX_KEYS.' keys.';
            }

            foreach ($value as $key => $entry) {
                $key = (string) $key;

                if (preg_match(self::FLAT_MAP_KEY_PATTERN, $key) !== 1) {
                    // Never echo the raw key: only a control-stripped, length-
                    // limited label reaches the response.
                    $failures[$map][] = 'The '.$map.' key ['.$this->keyLabel($key).'] is not a valid metric key.';

                    continue;
                }

                $field = $map.'.'.$key;

                if (is_array($entry)) {
                    $failures[$field][] = 'The '.$field.' field must be a scalar value, not a nested array or object.';

                    continue;
                }

                if (is_string($entry) && strlen($entry) > self::FLAT_MAP_MAX_STRING_BYTES) {
                    $failures[$field][] = 'The '.$field.' field must not be greater than '.self::FLAT_MAP_MAX_STRING_BYTES.' bytes.';
                }
            }
        }

        return $failures;
    }

    /**
     * Every position that must be a JSON object, checked against the decoded
     * tree so a list (`[]` or `[…]`) is a shape error rather than a missing
     * or malformed sub-tree. An empty list and an empty object are the same
     * after `toArray()`, so this cannot be done on the converted array.
     *
     * @return array<string, list<string>>
     */
    private function listFailures(stdClass $decoded, array $data): array
    {
        $failures = [];

        foreach (self::OBJECT_POSITIONS as $position) {
            $value = $decoded;

            foreach (explode('.', $position) as $segment) {
                $value = is_object($value) ? ($value->{$segment} ?? null) : null;
            }

            if ($this->isList($value)) {
                $failures[$position][] = 'The '.$position.' field must be a JSON object.';
            }
        }

        return $failures;
    }

    /**
     * True for a decoded JSON list (including the empty list); objects and
     * scalars are not lists.
     */
    private function isList(mixed $value): bool
    {
        return is_array($value) && array_is_list($value);
    }

    /**
     * A safe label for a client-supplied flat-map key. The key itself is
     * never echoed: it may contain control bytes or invalid UTF-8, which must
     * not reach the 422 body even as a substituted replacement character.
     */
    private function keyLabel(string $key): string
    {
        $visible = preg_replace('/[^\x20-\x7E]/', '', $key) ?? '';
        $visible = Str::limit($visible, 32, '…');

        return $visible === '' ? '(unnamed)' : $visible;
    }

    private function toPayload(array $data): IngestPayload
    {
        $receivedAt = CarbonImmutable::now('UTC');
        $collectedAt = CarbonImmutable::parse((string) Arr::get($data, 'agent.collected_at'))->utc();

        $earliest = $receivedAt->subSeconds(self::CLOCK_SKEW_SECONDS);
        $latest = $receivedAt->addSeconds(self::CLOCK_SKEW_SECONDS);

        if ($collectedAt->lessThan($earliest)) {
            $collectedAt = $earliest;
        } elseif ($collectedAt->greaterThan($latest)) {
            $collectedAt = $latest;
        }

        return new IngestPayload(
            schemaVersion: (int) Arr::get($data, 'schema_version'),
            agentId: (string) Arr::get($data, 'agent.id'),
            hostname: (string) Arr::get($data, 'agent.hostname'),
            panel: (string) Arr::get($data, 'agent.panel'),
            collectedAt: $collectedAt,
            receivedAt: $receivedAt,
            intervalSeconds: (int) Arr::get($data, 'agent.interval_seconds'),
            osName: $this->optionalString($data, 'system.os.name'),
            osVersion: $this->optionalString($data, 'system.os.version'),
            kernel: $this->optionalString($data, 'system.os.kernel'),
            agentVersion: $this->optionalString($data, 'agent.version'),
            uptimeSeconds: $this->optionalInt($data, 'system.uptime_seconds'),
            metrics: (array) Arr::get($data, 'metrics'),
            panelMetrics: $this->flatMap($data, 'panel_metrics'),
            extensions: $this->flatMap($data, 'extensions'),
        );
    }

    private function optionalString(array $data, string $key): ?string
    {
        $value = Arr::get($data, $key);

        return is_string($value) ? $value : null;
    }

    private function optionalInt(array $data, string $key): ?int
    {
        $value = Arr::get($data, $key);

        return is_int($value) ? $value : null;
    }

    /**
     * @return array<string, string|int|float|bool|null>
     */
    private function flatMap(array $data, string $map): array
    {
        $value = Arr::get($data, $map);

        return is_array($value) ? $value : [];
    }
}
