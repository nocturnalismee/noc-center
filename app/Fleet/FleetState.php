<?php

namespace App\Fleet;

use App\Enums\ServerStatus;
use App\Models\Server;
use App\Services\SettingsRepository;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Single source of truth for the fleet-state vocabulary: liveness status, the
 * status tabs, the tab counts, the status sort and the dashboard summary.
 *
 * The PHP rules and the SQL filters below must stay in lockstep; every other
 * caller (Server::status(), ServerTable, FleetSummary) delegates here.
 */
final class FleetState
{
    /**
     * Status tabs rendered by the server table, in display order.
     *
     * @var list<string>
     */
    public const TABS = ['all', 'online', 'attention', 'stale', 'offline', 'never'];

    public static function status(Server $server, ?CarbonInterface $now = null): ServerStatus
    {
        if ($server->last_seen_at === null) {
            return ServerStatus::NeverSeen;
        }

        if ($server->stale_after === null || $server->offline_after === null) {
            return ServerStatus::Offline;
        }

        $now ??= now();

        if ($now->lessThanOrEqualTo($server->stale_after)) {
            return ServerStatus::Online;
        }

        if ($now->lessThanOrEqualTo($server->offline_after)) {
            return ServerStatus::Stale;
        }

        return ServerStatus::Offline;
    }

    /**
     * @param  Builder<Server>  $query
     */
    public static function applyStatus(Builder $query, string $status, ?CarbonInterface $now = null): void
    {
        $now ??= now();

        match ($status) {
            'online' => $query
                ->whereNotNull('last_seen_at')
                ->whereNotNull('stale_after')
                ->whereNotNull('offline_after')
                ->where('stale_after', '>=', $now),
            'attention' => $query->whereIn('health', ['warning', 'critical']),
            'stale' => $query
                ->whereNotNull('last_seen_at')
                ->whereNotNull('stale_after')
                ->whereNotNull('offline_after')
                ->where('stale_after', '<', $now)
                ->where('offline_after', '>=', $now),
            'offline' => $query
                ->whereNotNull('last_seen_at')
                ->where(function (Builder $query) use ($now): void {
                    $query->whereNull('stale_after')
                        ->orWhereNull('offline_after')
                        ->orWhere(function (Builder $query) use ($now): void {
                            $query->where('stale_after', '<', $now)
                                ->where('offline_after', '<', $now);
                        });
                }),
            'never' => $query->whereNull('last_seen_at'),
            default => null,
        };
    }

    /**
     * Sort Online → Stale → Offline → Never, SQL-side.
     *
     * @param  Builder<Server>  $query
     */
    public static function applyStatusSort(Builder $query, string $direction, ?CarbonInterface $now = null): void
    {
        $now ??= now();
        $direction = $direction === 'desc' ? 'desc' : 'asc';

        // Online is checked first so an inconsistent row (stale_after in the
        // future but offline_after in the past) ranks Online, matching status().
        $query->orderByRaw(
            "CASE
                WHEN last_seen_at IS NULL THEN 3
                WHEN stale_after IS NULL OR offline_after IS NULL THEN 2
                WHEN stale_after >= ? THEN 0
                WHEN offline_after >= ? THEN 1
                ELSE 2
            END {$direction}",
            [$now, $now],
        );
    }

    /**
     * @param  Builder<Server>  $query
     * @return array{all:int,online:int,attention:int,stale:int,offline:int,never:int}
     */
    public static function counts(Builder $query, ?CarbonInterface $now = null): array
    {
        $now ??= now();

        $row = $query
            ->toBase()
            ->selectRaw(
                <<<'SQL'
                COUNT(*) AS all_count,
                COALESCE(SUM(CASE
                    WHEN last_seen_at IS NOT NULL
                        AND stale_after IS NOT NULL
                        AND offline_after IS NOT NULL
                        AND stale_after >= ?
                    THEN 1 ELSE 0 END), 0) AS online_count,
                COALESCE(SUM(CASE WHEN health IN ('warning', 'critical') THEN 1 ELSE 0 END), 0) AS attention_count,
                COALESCE(SUM(CASE
                    WHEN last_seen_at IS NOT NULL
                        AND stale_after IS NOT NULL
                        AND offline_after IS NOT NULL
                        AND stale_after < ?
                        AND offline_after >= ?
                    THEN 1 ELSE 0 END), 0) AS stale_count,
                COALESCE(SUM(CASE
                    WHEN last_seen_at IS NOT NULL
                        AND (
                            stale_after IS NULL
                            OR offline_after IS NULL
                            OR (stale_after < ? AND offline_after < ?)
                        )
                    THEN 1 ELSE 0 END), 0) AS offline_count,
                COALESCE(SUM(CASE WHEN last_seen_at IS NULL THEN 1 ELSE 0 END), 0) AS never_count
                SQL,
                [$now, $now, $now, $now, $now],
            )
            ->first();

        return [
            'all' => (int) $row->all_count,
            'online' => (int) $row->online_count,
            'attention' => (int) $row->attention_count,
            'stale' => (int) $row->stale_count,
            'offline' => (int) $row->offline_count,
            'never' => (int) $row->never_count,
        ];
    }

    /**
     * @return array{total:int,online:int,attention:int,hot:int,gaps:int,samples_per_minute:int}
     */
    public static function summary(SettingsRepository $settings, ?CarbonInterface $now = null): array
    {
        $now ??= now();
        $thresholds = self::usageThresholds($settings);

        // "Online" is the Online tab definition, so hot and samples only ever
        // look at servers that are reporting right now.
        $online = '(last_seen_at IS NOT NULL AND stale_after IS NOT NULL AND offline_after IS NOT NULL AND stale_after >= ?)';
        $hot = "({$online} AND (cpu_usage_pct >= ? OR mem_used_pct >= ? OR disk_used_pct >= ?))";

        $row = Server::query()
            ->toBase()
            ->selectRaw(
                <<<SQL
                COUNT(*) AS total,
                COALESCE(SUM(CASE WHEN {$online} THEN 1 ELSE 0 END), 0) AS online,
                COALESCE(SUM(CASE WHEN health IN ('warning', 'critical') THEN 1 ELSE 0 END), 0) AS attention,
                COALESCE(SUM(CASE WHEN {$hot} THEN 1 ELSE 0 END), 0) AS hot,
                COALESCE(SUM(CASE
                    WHEN {$online}
                    THEN 60.0 / (CASE WHEN interval_seconds > 0 THEN interval_seconds ELSE 1 END)
                    ELSE 0 END), 0) AS samples
                SQL,
                [
                    $now,
                    $now,
                    $thresholds['cpu']['warning'],
                    $thresholds['memory']['warning'],
                    $thresholds['disk']['warning'],
                    $now,
                ],
            )
            ->first();

        $total = (int) $row->total;
        $onlineCount = (int) $row->online;

        return [
            'total' => $total,
            'online' => $onlineCount,
            'attention' => (int) $row->attention,
            'hot' => (int) $row->hot,
            'gaps' => $total - $onlineCount,
            'samples_per_minute' => (int) round((float) $row->samples),
        ];
    }

    /**
     * @param  list<string>  $rules  Subset of cpu, memory, disk (default: all).
     * @return array{cpu?:array{warning:int,critical:int},memory?:array{warning:int,critical:int},disk?:array{warning:int,critical:int}}
     */
    public static function usageThresholds(SettingsRepository $settings, array $rules = ['cpu', 'memory', 'disk']): array
    {
        $thresholds = [];

        foreach ($rules as $rule) {
            $thresholds[$rule] = [
                'warning' => self::threshold($settings, $rule, 'warning'),
                'critical' => self::threshold($settings, $rule, 'critical'),
            ];
        }

        return $thresholds;
    }

    private static function threshold(SettingsRepository $settings, string $rule, string $key): int
    {
        $path = 'alerts.rules.'.$rule.'_high.'.$key;

        return (int) $settings->get($path, config($path));
    }
}
