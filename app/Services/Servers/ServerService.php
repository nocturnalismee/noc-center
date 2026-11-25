<?php

namespace App\Services\Servers;

use App\Alerts\AlertEngine;
use App\Enums\AlertStatus;
use App\Enums\ServerHealth;
use App\Events\ServerChanged;
use App\Models\Alert;
use App\Models\Server;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Tokens\TokenService;
use App\Support\Realtime;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ServerService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TokenService $tokenService,
        private readonly AlertEngine $alertEngine,
    ) {
        //
    }

    /**
     * Create a monitored server and record who registered it.
     *
     * `name`, `hostname` and the identifying metadata are trimmed before
     * validation, so a whitespace name is rejected as empty. An empty `panel`
     * is normalised to `none`. Name uniqueness includes soft-deleted rows
     * because the underlying unique index still reserves the name. Health,
     * issue codes and the active flag are forced on create, so they cannot be
     * mass-assigned. Metadata is never unique and the primary IP is
     * identification only, never derived from the request or agent.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws ValidationException
     */
    public function create(array $attributes, ?User $actor = null): Server
    {
        foreach (['name', 'hostname', 'server_type', 'provider', 'location', 'ip_address', 'label'] as $key) {
            if (isset($attributes[$key]) && is_string($attributes[$key])) {
                $attributes[$key] = trim($attributes[$key]);
            }
        }

        foreach (['server_type', 'provider', 'location', 'ip_address', 'label'] as $key) {
            if (($attributes[$key] ?? null) === '') {
                $attributes[$key] = null;
            }
        }

        $validated = Validator::make($attributes, [
            'name' => ['required', 'string', 'max:100', Rule::unique('servers', 'name')],
            'hostname' => ['required', 'string', 'max:255'],
            'panel' => ['sometimes', 'string', 'max:32'],
            'server_type' => ['sometimes', 'nullable', 'string', 'max:100'],
            'provider' => ['sometimes', 'nullable', 'string', 'max:100'],
            'location' => ['sometimes', 'nullable', 'string', 'max:100'],
            'ip_address' => ['sometimes', 'nullable', 'string', 'max:45', 'ip'],
            'label' => ['sometimes', 'nullable', 'string', 'max:100'],
            'alerts_enabled' => ['sometimes', 'boolean'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'interval_seconds' => ['sometimes', 'integer', 'between:10,3600'],
        ])->validate();

        $panel = $validated['panel'] ?? 'none';

        $server = Server::query()->create([
            'name' => $validated['name'],
            'hostname' => $validated['hostname'],
            'panel' => $panel === '' ? 'none' : $panel,
            'server_type' => $validated['server_type'] ?? null,
            'provider' => $validated['provider'] ?? null,
            'location' => $validated['location'] ?? null,
            'ip_address' => $validated['ip_address'] ?? null,
            'label' => $validated['label'] ?? null,
            'alerts_enabled' => filter_var($validated['alerts_enabled'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'notes' => $validated['notes'] ?? null,
            'interval_seconds' => (int) ($validated['interval_seconds'] ?? 60),
            'health' => ServerHealth::Unknown,
            'issue_codes' => [],
            'is_active' => true,
            'last_seen_at' => null,
            'stale_after' => null,
            'offline_after' => null,
        ]);

        $this->audit->log(
            'server.created',
            $server,
            ['server_id' => $server->getKey()],
            $actor?->id,
        );

        Realtime::emit(new ServerChanged(
            serverId: (string) $server->getKey(),
            change: 'created',
        ));

        return $server;
    }

    /**
     * Update editable metadata and collection settings.
     *
     * Health, issue codes and the active flag are owned by ingest/liveness and
     * are never written here. Blank optional fields become NULL. Name
     * uniqueness ignores the row being edited but still reserves names of
     * soft-deleted servers. When `alerts_enabled` changes from true to false,
     * the server's open/acknowledged alerts are closed administratively with
     * no recovery delivery (R39/R44).
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws ValidationException
     */
    public function update(Server $server, array $attributes, ?User $actor = null): Server
    {
        foreach (['name', 'hostname', 'server_type', 'provider', 'location', 'ip_address', 'label', 'notes'] as $key) {
            if (array_key_exists($key, $attributes) && is_string($attributes[$key])) {
                $attributes[$key] = trim($attributes[$key]);
            }
        }

        foreach (['server_type', 'provider', 'location', 'ip_address', 'label', 'notes'] as $key) {
            if (($attributes[$key] ?? null) === '') {
                $attributes[$key] = null;
            }
        }

        $validated = Validator::make($attributes, [
            'name' => ['required', 'string', 'max:100', Rule::unique('servers', 'name')->ignore($server->getKey())],
            'hostname' => ['required', 'string', 'max:255'],
            'server_type' => ['sometimes', 'nullable', 'string', 'max:100'],
            'provider' => ['sometimes', 'nullable', 'string', 'max:100'],
            'location' => ['sometimes', 'nullable', 'string', 'max:100'],
            'ip_address' => ['sometimes', 'nullable', 'string', 'max:45', 'ip'],
            'label' => ['sometimes', 'nullable', 'string', 'max:100'],
            'alerts_enabled' => ['sometimes', 'boolean'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'interval_seconds' => ['sometimes', 'integer', 'between:10,3600'],
        ])->validate();

        $wasEnabled = (bool) $server->alerts_enabled;

        $updated = DB::transaction(function () use ($server, $validated, $wasEnabled, $actor): Server {
            $payload = [];

            foreach (['name', 'hostname', 'server_type', 'provider', 'location', 'ip_address', 'label', 'notes'] as $key) {
                if (array_key_exists($key, $validated)) {
                    $payload[$key] = $validated[$key];
                }
            }

            if (array_key_exists('interval_seconds', $validated)) {
                $payload['interval_seconds'] = (int) $validated['interval_seconds'];
            }

            if (array_key_exists('alerts_enabled', $validated)) {
                $payload['alerts_enabled'] = filter_var($validated['alerts_enabled'], FILTER_VALIDATE_BOOLEAN);
            }

            $server->fill($payload)->save();

            $alertsEnabled = (bool) $server->alerts_enabled;
            $closed = 0;

            if ($wasEnabled && ! $alertsEnabled) {
                $closed = $this->alertEngine->closeForDisabledAlerts($server, $actor);
            }

            $this->audit->log(
                'server.updated',
                $server,
                [
                    'server_id' => $server->getKey(),
                    'alerts_enabled' => $alertsEnabled,
                    'closed_alerts' => $closed,
                ],
                $actor?->getKey(),
            );

            return $server;
        });

        Realtime::emit(new ServerChanged(
            serverId: (string) $updated->getKey(),
            change: 'updated',
        ));

        return $updated;
    }

    /**
     * Soft-delete a server as an administrative action.
     *
     * Every token is revoked, every open/acknowledged alert is resolved with
     * `notify: false` (no recovery email/Telegram — this is not a condition
     * recovery), and the deletion is audited. Metrics are retained; pruning
     * remains the retention job's responsibility.
     */
    public function delete(Server $server, ?User $actor = null): void
    {
        $serverId = (string) $server->getKey();

        DB::transaction(function () use ($server, $actor): void {
            $revoked = 0;

            foreach ($server->tokens()->get() as $token) {
                $wasRevoked = $token->revoked_at !== null;

                $this->tokenService->revoke($token, $actor);

                if (! $wasRevoked) {
                    $revoked++;
                }
            }

            $alerts = Alert::query()
                ->where('server_id', $server->getKey())
                ->whereIn('status', [AlertStatus::Open->value, AlertStatus::Acknowledged->value])
                ->lockForUpdate()
                ->get();

            foreach ($alerts as $alert) {
                $this->alertEngine->resolve($alert, $actor, 'Server deleted', false);
            }

            $server->delete();

            $this->audit->log(
                'server.deleted',
                $server,
                [
                    'server_id' => $server->getKey(),
                    'revoked_count' => $revoked,
                    'resolved_count' => $alerts->count(),
                ],
                $actor?->getKey(),
            );
        });

        Realtime::emit(new ServerChanged(
            serverId: $serverId,
            change: 'deleted',
        ));
    }
}
