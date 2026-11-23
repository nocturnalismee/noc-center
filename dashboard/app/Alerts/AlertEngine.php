<?php

namespace App\Alerts;

use App\Enums\AlertRule;
use App\Enums\AlertSeverity;
use App\Enums\AlertStatus;
use App\Enums\ServerHealth;
use App\Issues\IssueSet;
use App\Models\Alert;
use App\Models\Server;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\SettingsRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Open, update and resolve alerts for one server (spec §9.1, §9.3).
 *
 * Deduplication is structural: `alerts.active_key = "server_id:rule_key"` is
 * unique and only present while an alert is open or acknowledged. A rule that
 * keeps firing updates the existing row (`last_seen_at`, severity); a rule
 * that disappears resolves it and frees the key.
 *
 * Severity comes from the issue detail (`IssueSet`), never from a threshold
 * re-derivation (R24). Escalation is immediate; de-escalation keeps the
 * higher severity until the alert resolves, so a flapping warning/critical
 * pair does not silently downgrade an active page.
 *
 * `evaluate()` only resolves rules it evaluated (R7). Liveness rules
 * (`server_offline`, `server_stale`) belong to the scheduler, which calls
 * `syncRule()` for those two rules only.
 */
class AlertEngine
{
    /**
     * Rules evaluated from an ingest `IssueSet` (spec §9.1). Liveness rules are
     * deliberately absent: the scheduler owns them.
     *
     * @var list<AlertRule>
     */
    public const INGEST_RULES = [
        AlertRule::CpuHigh,
        AlertRule::MemoryHigh,
        AlertRule::DiskHigh,
        AlertRule::InodeHigh,
        AlertRule::LoadHigh,
        AlertRule::SmartFailed,
        AlertRule::SmartWarning,
        AlertRule::ServiceDown,
        AlertRule::MysqlUnreachable,
        AlertRule::QuotaOver,
        AlertRule::ModuleCacheStale,
        AlertRule::SecurityDisabled,
    ];

    /**
     * @var array<string, string>
     */
    private const TITLES = [
        'server_offline' => 'Server offline',
        'server_stale' => 'Server stale',
        'cpu_high' => 'CPU high',
        'memory_high' => 'Memory high',
        'disk_high' => 'Disk usage high',
        'inode_high' => 'Inode usage high',
        'load_high' => 'Load average high',
        'smart_failed' => 'SMART health failed',
        'smart_warning' => 'SMART warning',
        'service_down' => 'Service down',
        'mysql_unreachable' => 'MySQL unreachable',
        'quota_over' => 'Disk quota exceeded',
        'module_cache_stale' => 'Module data stale',
        'security_disabled' => 'Security disabled',
    ];

    public function __construct(
        private readonly AlertNotifier $notifier,
        private readonly AuditLogger $audit,
        private readonly SettingsRepository $settings,
    ) {
        //
    }

    /**
     * Evaluate the ingest rules for one server and reconcile its alerts.
     *
     * R39: a server with alerts disabled is skipped entirely — no alert is
     * opened and none is resolved. Closing the existing ones is the job of
     * `closeForDisabledAlerts()` on the true→false transition.
     */
    public function evaluate(Server $server, IssueSet $issues): void
    {
        if (! $this->alertsEnabled($server)) {
            return;
        }

        foreach (self::INGEST_RULES as $rule) {
            $active = $issues->has($rule->value);
            $detail = $issues->details[$rule->value] ?? [];

            $this->syncRule($server, $rule, $active, $detail);
        }
    }

    /**
     * Open, update or resolve one rule. `$context` is the issue detail
     * (`value`, `threshold`, `severity`) or, for a scheduler call, the same
     * shape. A rule that is still absent after re-reading the server must not
     * silently create a row on a disabled server.
     */
    public function syncRule(Server $server, AlertRule $rule, bool $active, array $context = []): ?Alert
    {
        if (! $this->alertsEnabled($server)) {
            return null;
        }

        $key = $server->getKey().':'.$rule->value;

        return DB::transaction(function () use ($server, $rule, $active, $context, $key): ?Alert {
            $existing = Alert::query()->where('active_key', $key)->first();

            if (! $active) {
                if ($existing !== null) {
                    $this->resolve($existing, null, 'Issue cleared');
                }

                return null;
            }

            $severity = $this->severity($context);

            if ($existing !== null) {
                $this->touch($existing, $severity, $context);

                return $existing;
            }

            $alert = Alert::query()->create([
                'server_id' => $server->getKey(),
                'rule_key' => $rule,
                'severity' => $severity,
                'status' => AlertStatus::Open,
                'active_key' => $key,
                'title' => $this->title($rule),
                'message' => $this->message($rule, $server, $context),
                'context_json' => $this->context($context),
                'first_seen_at' => now(),
                'last_seen_at' => now(),
                'notification_count' => 0,
            ]);

            $this->notifier->dispatch($alert, 'opened');

            return $alert;
        });
    }

    /**
     * Reconcile the two liveness rules for one server (Task 10).
     *
     * `server_stale` (warning) and `server_offline` (critical) are mutually
     * exclusive: at most one is open. The escalation is administrative, so the
     * rule that no longer holds is closed **without recovery** — recovery is
     * sent only when the server really comes back and the offline alert
     * resolves through the normal path (`$notify` true).
     *
     * `$activeRule` is null when the server is online (or never seen); then
     * both rules are closed, and a closed `server_offline` is a real
     * recovery.
     */
    public function evaluateLiveness(Server $server, ?AlertRule $activeRule): void
    {
        if (! $this->alertsEnabled($server)) {
            return;
        }

        $rules = [AlertRule::ServerStale, AlertRule::ServerOffline];
        $pending = [];
        $disabledClosed = 0;

        foreach ($rules as $rule) {
            if (! $this->livenessRuleEnabled($rule)) {
                // A rule switched off in settings must not open or update an
                // alert. An existing open/acknowledged row is closed
                // administratively, never as a condition recovery.
                $disabledClosed += $this->closeLivenessRule(
                    $server,
                    $rule,
                    'Alert rule disabled',
                    false,
                );

                continue;
            }

            $active = $rule === $activeRule;

            if (! $active) {
                // A stale→offline escalation is not a condition recovery; an
                // offline→online transition is.
                $pending[] = $rule;

                continue;
            }

            $this->syncRule($server, $rule, true, [
                'severity' => $rule === AlertRule::ServerOffline ? 'critical' : 'warning',
            ]);
        }

        $closed = 0;

        foreach ($pending as $rule) {
            // The two liveness rules are mutually exclusive. Leaving `stale`
            // for `offline` is an escalation (administrative, no recovery);
            // every other close of an open rule — going back online — is a
            // real condition recovery.
            $escalation = $rule === AlertRule::ServerStale && $activeRule === AlertRule::ServerOffline;

            $closed += $this->closeLivenessRule(
                $server,
                $rule,
                $escalation ? 'Server offline' : 'Issue cleared',
                ! $escalation,
            );
        }

        // Ingest owns `health` and `issue_codes` for an online server: the
        // sample that made it online was just committed by `StoreSample`,
        // which writes both for that sample atomically. Re-deriving them from
        // the alerts visible here could overwrite a critical or warning that
        // ingest detected — its alert evaluation runs only after the sample
        // transaction commits — so a return leaves the row untouched, unless
        // a disabled rule just closed an alert and health must be rebuilt.
        if ($activeRule === null) {
            if ($disabledClosed > 0) {
                $this->refreshServerHealth($server);
            }

            return;
        }

        // The deferral is self-correcting: the next `StoreSample` writes
        // `health` and `issue_codes` in the same transaction, so a code the
        // scheduler leaves behind cannot outlive the next sample.
        $this->refreshServerHealth($server);
    }

    /**
     * Health is the aggregate of the open/acknowledged alerts (spec §9.3,
     * §10): `critical` if any is critical, `warning` if any is warning, else
     * `ok` once a sample exists and `unknown` when the server was never seen.
     * `issue_codes` is the set of those alerts' rule keys.
     *
     * Liveness calls this only while a server is stale or offline. For an
     * online server `StoreSample` owns both columns: see
     * `evaluateLiveness()`.
     */
    public function refreshServerHealth(Server $server): void
    {
        $open = Alert::query()
            ->where('server_id', $server->getKey())
            ->whereIn('status', [AlertStatus::Open->value, AlertStatus::Acknowledged->value])
            ->get();

        $codes = $open
            ->pluck('rule_key')
            ->map(static fn (AlertRule $rule): string => $rule->value)
            ->unique()
            ->values()
            ->all();

        $health = match (true) {
            $open->contains(fn (Alert $alert): bool => $alert->severity === AlertSeverity::Critical) => ServerHealth::Critical,
            $open->isNotEmpty() => ServerHealth::Warning,
            $this->hasSample($server) => ServerHealth::Ok,
            default => ServerHealth::Unknown,
        };

        $server->forceFill([
            'health' => $health,
            'issue_codes' => $codes,
        ])->save();
    }

    /**
     * Close one liveness rule without creating it. The reason distinguishes an
     * escalation ("Server offline") from a recovery ("Issue cleared").
     *
     * @return int 1 when an alert was closed, 0 when the rule was not open.
     */
    private function closeLivenessRule(Server $server, AlertRule $rule, string $note, bool $notify): int
    {
        $existing = Alert::query()
            ->where('active_key', $server->getKey().':'.$rule->value)
            ->first();

        if ($existing === null) {
            return 0;
        }

        $this->resolve($existing, null, $note, $notify);

        return 1;
    }

    private function hasSample(Server $server): bool
    {
        if ($server->last_sample_at !== null) {
            return true;
        }

        return DB::table('metric_samples')->where('server_id', $server->getKey())->exists();
    }

    /**
     * R39: administratively close every open/acknowledged alert of a server
     * whose alerts were switched off. No recovery is sent (this is not a
     * condition recovery), `active_key` is cleared so re-enabling can open
     * fresh alerts, and one audit row records the count.
     *
     * Returns the number of alerts closed; zero writes no audit.
     */
    public function closeForDisabledAlerts(Server $server, ?User $actor = null): int
    {
        $closed = DB::transaction(function () use ($server, $actor): int {
            $alerts = Alert::query()
                ->where('server_id', $server->getKey())
                ->whereIn('status', [AlertStatus::Open->value, AlertStatus::Acknowledged->value])
                ->lockForUpdate()
                ->get();

            foreach ($alerts as $alert) {
                $this->resolve($alert, $actor, 'Alerts disabled for this server', false);
            }

            return $alerts->count();
        });

        if ($closed === 0) {
            return 0;
        }

        $this->audit->log(
            'server.alerts_disabled',
            $server,
            ['server_id' => $server->getKey(), 'closed_count' => $closed],
            $actor?->getKey(),
        );

        return $closed;
    }

    /**
     * Resolve an alert and, by default, send exactly one recovery per channel.
     *
     * `$notify` defaults to true on purpose (spec §9): an operator resolving an
     * alert asserts that the condition is gone. A maintenance close — "we are
     * doing this deliberately, do not celebrate" — is a Task 19 UI decision
     * that will pass `$notify: false`; liveness never suppresses recovery here.
     */
    public function resolve(Alert $alert, ?User $actor = null, ?string $note = null, bool $notify = true): void
    {
        $resolved = DB::transaction(function () use ($alert, $actor, $note, $notify): ?Alert {
            $current = Alert::query()->lockForUpdate()->find($alert->getKey());

            if ($current === null || $current->status === AlertStatus::Resolved) {
                return null;
            }

            // The in-app channel is the `alerts` row itself, so the recovery
            // reaches operators even when the database queue runs after the
            // transaction commits: the row is visible as resolved by then.
            $current->forceFill([
                'status' => AlertStatus::Resolved,
                'active_key' => null,
                'resolved_at' => now(),
                'resolved_by' => $actor?->getKey(),
                'resolution_note' => $note,
            ])->save();

            if ($notify) {
                // Inside the transaction: the `alert_deliveries` rows commit
                // with the resolution (no half-resolved alert on a rollback),
                // and the queued jobs are dispatched `afterCommit()`.
                $this->notifier->dispatch($current, 'resolved');
            }

            $alert->setRawAttributes($current->getAttributes(), true);

            return $current;
        });

        if ($resolved === null) {
            return;
        }

        $this->audit->log(
            'alert.resolved',
            $resolved,
            array_filter([
                'server_id' => $resolved->server_id,
                'rule_key' => $resolved->rule_key->value,
                'resolved_by' => $actor?->getKey(),
                'note' => $note,
                'notified' => $notify,
            ], static fn (mixed $value): bool => $value !== null),
            $actor?->getKey(),
        );
    }

    /**
     * Acknowledge an alert: it stays visible, stops reminders, and keeps its
     * `active_key` so the next evaluation does not open a duplicate.
     */
    public function acknowledge(Alert $alert, User $actor): void
    {
        $acknowledged = DB::transaction(function () use ($alert, $actor): ?Alert {
            $current = Alert::query()->lockForUpdate()->find($alert->getKey());

            if ($current === null || $current->status !== AlertStatus::Open) {
                return null;
            }

            $current->forceFill([
                'status' => AlertStatus::Acknowledged,
                'acknowledged_at' => now(),
                'acknowledged_by' => $actor->getKey(),
            ])->save();

            return $current;
        });

        if ($acknowledged === null) {
            return;
        }

        $alert->setRawAttributes($acknowledged->getAttributes(), true);

        $this->audit->log(
            'alert.acknowledged',
            $acknowledged,
            ['server_id' => $acknowledged->server_id, 'rule_key' => $acknowledged->rule_key->value],
            $actor->getKey(),
        );
    }

    private function touch(Alert $alert, AlertSeverity $severity, array $context): void
    {
        $attributes = [
            'last_seen_at' => now(),
            'context_json' => $this->context($context),
            'message' => $this->message($alert->rule_key, Server::query()->find($alert->server_id), $context),
        ];

        // Escalation is immediate; recovery to a lower severity happens by
        // resolving, so an active alert never silently downgrades.
        if ($severity === AlertSeverity::Critical) {
            $attributes['severity'] = AlertSeverity::Critical;
        }

        $alert->forceFill($attributes)->save();
    }

    private function alertsEnabled(Server $server): bool
    {
        // A stored row is authoritative; the model default is `true`, so a
        // freshly created instance behaves like the column default.
        return $server->alerts_enabled !== false;
    }

    /**
     * Liveness rules can be switched off from the alert settings page. The
     * ingest rules are handled by `IssueDetector`, which already reads its own
     * `enabled` override.
     */
    private function livenessRuleEnabled(AlertRule $rule): bool
    {
        return filter_var($this->settings->get(
            'alerts.rules.'.$rule->value.'.enabled',
            config()->get('alerts.rules.'.$rule->value.'.enabled'),
        ), FILTER_VALIDATE_BOOLEAN);
    }

    private function severity(array $context): AlertSeverity
    {
        $severity = $context['severity'] ?? null;

        if ($severity instanceof AlertSeverity) {
            return $severity;
        }

        return is_string($severity) && AlertSeverity::tryFrom($severity) === AlertSeverity::Critical
            ? AlertSeverity::Critical
            : AlertSeverity::Warning;
    }

    private function title(AlertRule $rule): string
    {
        return self::TITLES[$rule->value] ?? Str::headline($rule->value);
    }

    private function message(AlertRule $rule, ?Server $server, array $context): string
    {
        $value = $context['value'] ?? null;
        $threshold = $context['threshold'] ?? null;

        $rendered = is_scalar($value) ? (string) $value : 'reported';

        return $threshold === null
            ? sprintf('%s reported on %s', $this->title($rule), $server?->name ?? 'this server')
            : sprintf('%s on %s: %s (threshold %s)', $this->title($rule), $server?->name ?? 'this server', $rendered, is_scalar($threshold) ? (string) $threshold : '');
    }

    /**
     * Only the fields the alert needs; a `query` or token value in a detail
     * is never copied (R30/R31 audit redaction is not a substitute).
     *
     * @return array<string, mixed>
     */
    private function context(array $detail): array
    {
        $context = [];

        foreach (['value', 'threshold', 'severity'] as $key) {
            if (array_key_exists($key, $detail)) {
                $context[$key] = $detail[$key];
            }
        }

        return $context;
    }
}
