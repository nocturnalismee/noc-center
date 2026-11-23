<?php

namespace App\Services\Servers;

use App\Enums\ServerHealth;
use App\Models\Server;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ServerService
{
    public function __construct(private readonly AuditLogger $audit)
    {
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
            'alerts_enabled' => (bool) ($validated['alerts_enabled'] ?? true),
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

        return $server;
    }
}
