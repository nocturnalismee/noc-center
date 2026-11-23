#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Ingest load check for the NOC dashboard (spec §17.3).
 *
 * Reads a `noc:make-load-fixture` file and posts each entry's schema-v1
 * payload to `/api/v1/ingest` with its Bearer token, using `curl_multi`.
 *
 * By default every virtual server sends once per its payload
 * `agent.interval_seconds` (60 s in the fixture), and the 100 initial sends are
 * phase-staggered across that interval so the run models a fleet installed at
 * different times rather than a thundering herd. `--no-stagger` sends all
 * servers at t=0 for a deliberate burst probe.
 *
 * Pass `--interval` only when deliberately testing more traffic than the agent
 * contract allows (expect 429s, which are reported but not treated as
 * failures).
 *
 * Usage:
 *   php tools/load-ingest.php \
 *       --url=http://127.0.0.1:8000/api/v1/ingest \
 *       --fixture=/tmp/noc-load.json \
 *       --seconds=60 --concurrency=100
 *
 * Optional:
 *   --interval=5     seconds between runs per virtual server (default: payload)
 *   --no-stagger     send every server at t=0 instead of spreading the first
 *                    wave across one reporting interval
 *   --timeout=10     per-request total timeout in seconds
 *   --connect-timeout=5
 *   --log=PATH       Laravel log to scan for new `database is locked` lines
 *                    (default: storage/logs/laravel.log)
 *
 * Exit codes:
 *   0  no 5xx responses, no connection errors, no `database is locked`
 *   1  a 5xx response, a connection/TLS error, or a locked-database error
 *   2  invalid arguments or fixture
 */
const USER_AGENT = 'noc-load/1.0';

function usage(string $message = ''): never
{
    if ($message !== '') {
        fwrite(STDERR, $message.PHP_EOL);
    }

    fwrite(STDERR, <<<'USAGE'
Usage: php tools/load-ingest.php --url=URL --fixture=PATH [--seconds=60]
       [--concurrency=100] [--interval=N] [--timeout=10]
       [--connect-timeout=5] [--log=PATH]

  --url              Ingest endpoint, e.g. http://127.0.0.1:8000/api/v1/ingest
  --fixture          JSON file written by `artisan noc:make-load-fixture`
  --seconds          Run length in seconds (default 60)
  --concurrency      Maximum in-flight requests (default 100)
  --interval         Seconds per virtual-server run (default: agent interval)
  --no-stagger       Send every server at t=0 (default: spread the first wave)
  --timeout          Per-request total timeout in seconds (default 10)
  --connect-timeout  Per-request connection timeout in seconds (default 5)
  --log              Laravel log to scan for `database is locked` (default:
                     storage/logs/laravel.log)
USAGE);
    exit(2);
}

/**
 * Read `--name=value` and `--name value` forms; the first occurrence wins.
 *
 * @param  list<string>  $argv
 */
function option(array $argv, string $name, ?string $default = null): ?string
{
    $prefix = '--'.$name.'=';

    foreach (array_slice($argv, 1) as $position => $argument) {
        if (str_starts_with($argument, $prefix)) {
            return substr($argument, strlen($prefix));
        }

        if ($argument === '--'.$name) {
            $next = $argv[$position + 2] ?? null;

            if ($next !== null && ! str_starts_with($next, '--')) {
                return $next;
            }

            return '';
        }
    }

    return $default;
}

function positiveInt(string $option, string $value, int $default, int $min = 1, int $max = 3600): int
{
    if ($value === '') {
        return $default;
    }

    if (! ctype_digit($value)) {
        usage(sprintf('--%s must be a positive integer.', $option));
    }

    $number = (int) $value;

    if ($number < $min || $number > $max) {
        usage(sprintf('--%s must be between %d and %d.', $option, $min, $max));
    }

    return $number;
}

/**
 * @return list<array{name: string, token: string, payload: array<string, mixed>, interval: int}>
 */
function loadFixture(string $path): array
{
    if (! is_file($path) || ! is_readable($path)) {
        usage(sprintf('Fixture not found or unreadable: %s', $path));
    }

    try {
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        usage('Fixture is not valid JSON: '.$exception->getMessage());
    }

    if (! is_array($decoded) || ! array_is_list($decoded) || $decoded === []) {
        usage('Fixture must be a non-empty JSON list of {name, token, payload} entries.');
    }

    $entries = [];

    foreach ($decoded as $index => $entry) {
        if (! is_array($entry)
            || ! is_string($entry['name'] ?? null)
            || ! is_string($entry['token'] ?? null)
            || $entry['token'] === ''
            || ! is_array($entry['payload'] ?? null)) {
            usage(sprintf('Fixture entry %d must contain name, token, and payload.', $index));
        }

        $interval = $entry['payload']['agent']['interval_seconds'] ?? 60;
        $interval = is_numeric($interval) ? (int) $interval : 60;

        $entries[] = [
            'name' => $entry['name'],
            'token' => $entry['token'],
            'payload' => $entry['payload'],
            'interval' => max(1, $interval),
        ];
    }

    return $entries;
}

function logOffset(string $path): int
{
    return is_file($path) ? (int) filesize($path) : 0;
}

function lockedInLog(string $path, int $offset): int
{
    if (! is_file($path) || ! is_readable($path)) {
        return 0;
    }

    $size = (int) filesize($path);

    if ($size < $offset) {
        // The log was rotated/truncated during the run; read it from the top.
        $offset = 0;
    }

    $handle = fopen($path, 'rb');

    if ($handle === false) {
        return 0;
    }

    fseek($handle, $offset);
    $tail = stream_get_contents($handle) ?: '';
    fclose($handle);

    return substr_count(strtolower($tail), 'database is locked');
}

function percentile(array $sorted, float $percentile): float
{
    if ($sorted === []) {
        return 0.0;
    }

    $rank = (int) ceil($percentile / 100 * count($sorted));
    $rank = max(1, min(count($sorted), $rank));

    return (float) $sorted[$rank - 1];
}

$url = option($argv, 'url');
$fixturePath = option($argv, 'fixture');

if (! is_string($url) || $url === '' || ! is_string($fixturePath) || $fixturePath === '') {
    usage('--url and --fixture are required.');
}

$parts = parse_url($url);

if (! is_array($parts) || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) || ! isset($parts['host'])) {
    usage('--url must be an http:// or https:// URL.');
}

$seconds = positiveInt('seconds', (string) option($argv, 'seconds', '60'), 60, 1, 86400);
$concurrency = positiveInt('concurrency', (string) option($argv, 'concurrency', '100'), 100, 1, 1000);
$intervalOverride = option($argv, 'interval');
$timeout = positiveInt('timeout', (string) option($argv, 'timeout', '10'), 10, 1, 300);
$connectTimeout = positiveInt('connect-timeout', (string) option($argv, 'connect-timeout', '5'), 5, 1, 300);
$logPath = (string) option($argv, 'log', dirname(__DIR__).'/storage/logs/laravel.log');

$entries = loadFixture($fixturePath);
$logOffset = logOffset($logPath);

$start = microtime(true);
$deadline = $start + $seconds;

$states = [];

foreach ($entries as $entry) {
    $states[] = [
        'entry' => $entry,
        'next' => $start,
        'interval' => $intervalOverride !== null && $intervalOverride !== ''
            ? positiveInt('interval', $intervalOverride, $entry['interval'], 1, 86400)
            : $entry['interval'],
    ];
}

// A real 100-server fleet was installed at different times, so its one-minute
// reports are spread across the interval. Staggering the first wave keeps the
// check representative and still sends every server once during a 60 s run.
$staggered = option($argv, 'no-stagger') === null;
$stateCount = count($states);

if ($staggered) {
    foreach ($states as $index => &$state) {
        $state['next'] = $start + (($index / $stateCount) * $state['interval']);
    }

    unset($state);
}

$multi = curl_multi_init();
$active = [];
$latencies = [];
$statusCounts = [];
$requestBytes = 0;
$responseBytes = 0;
$requests = 0;
$fiveXx = 0;
$connectionErrors = 0;
$lockedInBodies = 0;
$sentPerServer = array_fill(0, count($states), 0);
$nextServer = 0;

$launch = function (int $stateIndex) use (&$states, &$active, &$sentPerServer, &$requestBytes, $multi, $url, $timeout, $connectTimeout): void {
    $entry = $states[$stateIndex]['entry'];
    $body = json_encode($entry['payload'], JSON_THROW_ON_ERROR);
    $requestBytes += strlen($body);

    $handle = curl_init();

    curl_setopt_array($handle, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => false,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => $connectTimeout,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer '.$entry['token'],
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: '.USER_AGENT,
            'X-Noc-Agent-Version: 0.1.0',
        ],
    ]);

    $id = spl_object_id($handle);

    $active[$id] = [
        'handle' => $handle,
        'name' => $entry['name'],
        'started' => microtime(true),
    ];

    $sentPerServer[$stateIndex]++;
    curl_multi_add_handle($multi, $handle);
};

while (true) {
    $now = microtime(true);

    // Launch every due virtual server while the concurrency budget allows.
    if ($now < $deadline) {
        for ($checked = 0; $checked < count($states) && count($active) < $concurrency; $checked++) {
            $index = $nextServer % count($states);
            $nextServer++;

            if ($states[$index]['next'] > microtime(true)) {
                continue;
            }

            $launch($index);
            $states[$index]['next'] = microtime(true) + $states[$index]['interval'];
        }
    }

    if ($active === []) {
        if (microtime(true) >= $deadline) {
            break;
        }

        $earliest = min(array_map(static fn (array $state): float => $state['next'], $states));
        $sleep = min(0.05, max(0.001, $earliest - microtime(true)));
        usleep((int) round($sleep * 1_000_000));

        continue;
    }

    do {
        $multiResult = curl_multi_exec($multi, $running);
    } while ($multiResult === CURLM_CALL_MULTI_PERFORM);

    while (($info = curl_multi_info_read($multi)) !== false) {
        $handle = $info['handle'];
        $id = spl_object_id($handle);
        $meta = $active[$id] ?? null;

        if ($meta === null) {
            continue;
        }

        $body = curl_multi_getcontent($handle) ?: '';
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        $latency = (microtime(true) - $meta['started']) * 1000;

        $latencies[] = $latency;
        $requests++;
        $responseBytes += strlen($body);
        $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;

        if ($status >= 500) {
            $fiveXx++;
        }

        if ($status === 0 || $error !== '') {
            $connectionErrors++;
        }

        if (stripos($body, 'database is locked') !== false) {
            $lockedInBodies++;
        }

        curl_multi_remove_handle($multi, $handle);
        curl_close($handle);
        unset($active[$id]);
    }

    if ($running > 0) {
        curl_multi_select($multi, 0.1);
    }
}

curl_multi_close($multi);
$elapsed = microtime(true) - $start;
$logLocked = lockedInLog($logPath, $logOffset);

sort($latencies, SORT_NUMERIC);

$count = count($latencies);
$p50 = percentile($latencies, 50);
$p95 = percentile($latencies, 95);
$p99 = percentile($latencies, 99);
$max = $count > 0 ? (float) $latencies[$count - 1] : 0.0;
$min = $count > 0 ? (float) $latencies[0] : 0.0;
$mean = $count > 0 ? array_sum($latencies) / $count : 0.0;

ksort($statusCounts);

printf("Load ingest: %d virtual servers, %.1fs requested, %.1fs elapsed, concurrency %d, staggered %s\n", count($states), (float) $seconds, $elapsed, $concurrency, $staggered ? 'yes' : 'no');
printf("Requests: %d (%.1f/s), request bytes: %d, response bytes: %d\n", $requests, $elapsed > 0 ? $requests / $elapsed : 0.0, $requestBytes, $responseBytes);
printf("Status: %s\n", $statusCounts === [] ? 'none' : implode(' ', array_map(
    static fn (int $status, int $total): string => $status.'='.$total,
    array_keys($statusCounts),
    array_values($statusCounts),
)));
printf(
    "Latency ms: min=%.1f p50=%.1f p95=%.1f p99=%.1f max=%.1f mean=%.1f\n",
    $min,
    $p50,
    $p95,
    $p99,
    $max,
    $mean,
);
printf("SQLite database is locked: %d (responses: %d, log: %d)\n", $lockedInBodies + $logLocked, $lockedInBodies, $logLocked);
printf("5xx: %d, connection errors: %d, total answer bytes: %d\n", $fiveXx, $connectionErrors, $responseBytes);

if ($requests === 0) {
    fwrite(STDERR, "FAIL: no request completed.\n");
    exit(1);
}

if ($fiveXx > 0 || $connectionErrors > 0 || $lockedInBodies + $logLocked > 0) {
    fwrite(STDERR, "FAIL: 5xx, connection errors, or `database is locked` observed.\n");
    exit(1);
}

exit(0);
