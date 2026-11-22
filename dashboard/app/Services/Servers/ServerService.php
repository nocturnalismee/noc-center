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
     * Name uniqueness includes soft-deleted rows because the underlying
     * unique index still reserves the name.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws ValidationException
     */
    public function create(array $attributes, ?User $actor = null): Server
    {
        $validated = Validator::make($attributes, [
            'name' => ['required', 'string', 'max:100', Rule::unique('servers', 'name')],
            'hostname' => ['required', 'string', 'max:255'],
            'panel' => ['sometimes', 'string', 'max:32'],
            'group_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'interval_seconds' => ['sometimes', 'integer', 'between:10,3600'],
        ])->validate();

        $server = Server::query()->create([
            'name' => $validated['name'],
            'hostname' => $validated['hostname'],
            'panel' => $validated['panel'] ?? 'none',
            'group_name' => $validated['group_name'] ?? null,
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
