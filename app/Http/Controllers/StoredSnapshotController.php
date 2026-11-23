<?php

namespace App\Http\Controllers;

use App\Models\Server;
use App\Services\AuditLogger;
use App\Support\MysqlQueryMasker;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class StoredSnapshotController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function __invoke(Server $server): Response
    {
        Gate::authorize('viewSnapshot', $server);

        $sample = $server->samples()
            ->with('detail')
            ->orderByDesc('collected_at')
            ->orderByDesc('id')
            ->first();

        $detail = $sample?->detail;

        $panel = $this->maskQueries($detail?->panel_metrics ?? []);
        $extensions = $this->maskQueries($detail?->extensions ?? []);

        $snapshot = null;

        if ($sample !== null) {
            $json = json_encode([
                'sample_id' => $sample->id,
                'collected_at' => $sample->collected_at?->toIso8601ZuluString(),
                'received_at' => $sample->received_at?->toIso8601ZuluString(),
                'interval_seconds' => $sample->interval_seconds,
                'payload_bytes' => $sample->payload_bytes,
                'panel_metrics' => $panel,
                'extensions' => $extensions,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

            $snapshot = $json === false ? null : $json;
        }

        $this->audit->log('server.snapshot_viewed', $server, [
            'sample_id' => $sample?->id,
        ]);

        return response()
            ->view('servers.snapshot', [
                'server' => $server,
                'sample' => $sample,
                'snapshot' => $snapshot,
            ])
            ->header('Cache-Control', 'no-store');
    }

    /**
     * @param  array<string, mixed>  $flat
     * @return array<string, mixed>
     */
    private function maskQueries(array $flat): array
    {
        foreach ($flat as $key => $value) {
            $normalized = strtolower((string) $key);

            if (is_string($value) && (str_ends_with($normalized, '.info') || $normalized === 'info')) {
                $flat[$key] = MysqlQueryMasker::mask($value);
            }
        }

        return $flat;
    }
}
