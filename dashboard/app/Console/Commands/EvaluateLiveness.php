<?php

namespace App\Console\Commands;

use App\Alerts\AlertEngine;
use App\Enums\AlertRule;
use App\Enums\ServerStatus;
use App\Models\Server;
use Illuminate\Console\Command;

/**
 * Stale and offline evaluation for every monitored server (spec §9.1, §10).
 *
 * A dead server cannot send a sample, so its liveness rules cannot be
 * evaluated at ingest: this command runs every minute and derives the status
 * from the absolute `stale_after`/`offline_after` timestamps through
 * `Server::status()` (R21), never from a re-computed duration.
 *
 * - `server_stale` (warning) while `stale_after < now <= offline_after`;
 * - `server_offline` (critical) once `now > offline_after`.
 *
 * The two rules are mutually exclusive. Stale→offline closes the stale alert
 * without recovery; a server that returns resolves the offline alert with one
 * recovery per channel that had an `opened` row. `servers.health` is then
 * derived from whatever alerts remain open.
 *
 * R39: servers that are soft-deleted, inactive, or have alerts disabled are
 * skipped entirely — no alert is opened and none is resolved.
 */
class EvaluateLiveness extends Command
{
    protected $signature = 'noc:evaluate-liveness';

    protected $description = 'Open and resolve stale and offline server alerts';

    public function handle(AlertEngine $engine): int
    {
        $evaluated = 0;

        Server::query()
            ->where('is_active', true)
            ->chunkById(100, function ($servers) use ($engine, &$evaluated): void {
                foreach ($servers as $server) {
                    // The chunk query is a projection; re-read the row so the
                    // model carries every attribute (notably `alerts_enabled`,
                    // whose model default is `true`, never a null that the
                    // boolean cast cannot resolve).
                    $server = Server::query()->find($server->getKey());

                    if ($server === null || $server->alerts_enabled !== true) {
                        continue;
                    }

                    $engine->evaluateLiveness($server, $this->ruleFor($server));

                    $evaluated++;
                }
            });

        $this->info(sprintf('Evaluated liveness for %d server(s).', $evaluated));

        return self::SUCCESS;
    }

    /**
     * The rule the server's current status implies, or null when online.
     *
     * `never_seen` gets no alert: there is no interval and therefore no
     * threshold, and a server registered a moment ago is not offline yet.
     * `AlertEngine::evaluateLiveness()` refreshes `servers.health` itself,
     * only when it has something to change.
     */
    private function ruleFor(Server $server): ?AlertRule
    {
        return match ($server->status()) {
            ServerStatus::Stale => AlertRule::ServerStale,
            ServerStatus::Offline => AlertRule::ServerOffline,
            default => null,
        };
    }
}
