<?php

namespace App\Services;

use App\Models\Alert;
use App\Models\Server;
use Illuminate\Support\Facades\Cache;

class FleetSummary
{
    public const CACHE_KEY = 'fleet.summary';

    public const CACHE_SECONDS = 20;

    /**
     * @return array{total:int,online:int,attention:int,hot:int,gaps:int,open_alerts:int,samples_per_minute:int}
     */
    public function summary(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, function (): array {
            $now = now()->utc()->toDateTimeString();

            $servers = Server::query()
                ->toBase()
                ->selectRaw(
                    <<<'SQL'
                    COUNT(*) AS total,
                    COALESCE(SUM(CASE
                        WHEN last_seen_at IS NOT NULL
                            AND stale_after IS NOT NULL
                            AND offline_after IS NOT NULL
                            AND stale_after >= ?
                        THEN 1 ELSE 0 END), 0) AS online,
                    COALESCE(SUM(CASE WHEN health IN ('warning', 'critical') THEN 1 ELSE 0 END), 0) AS attention,
                    COALESCE(SUM(CASE
                        WHEN cpu_usage_pct >= 90
                            OR mem_used_pct >= 90
                            OR disk_used_pct >= 90
                        THEN 1 ELSE 0 END), 0) AS hot,
                    COALESCE(SUM(CASE
                        WHEN last_seen_at IS NULL
                            OR stale_after IS NULL
                            OR offline_after IS NULL
                            OR stale_after < ?
                        THEN 1 ELSE 0 END), 0) AS gaps,
                    COALESCE(SUM(CASE
                        WHEN last_seen_at IS NOT NULL
                            AND stale_after IS NOT NULL
                            AND offline_after IS NOT NULL
                            AND stale_after >= ?
                        THEN 60.0 / (CASE WHEN interval_seconds > 0 THEN interval_seconds ELSE 1 END) ELSE 0 END), 0) AS samples
                    SQL,
                    [$now, $now, $now],
                )
                ->first();

            $openAlerts = Alert::query()
                ->where('status', 'open')
                ->count();

            return [
                'total' => (int) $servers->total,
                'online' => (int) $servers->online,
                'attention' => (int) $servers->attention,
                'hot' => (int) $servers->hot,
                'gaps' => (int) $servers->gaps,
                'open_alerts' => $openAlerts,
                'samples_per_minute' => (int) round((float) $servers->samples),
            ];
        });
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
