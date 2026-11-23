<?php

namespace App\Livewire\Servers;

use App\Models\Server;
use App\Services\Servers\ServerService;
use App\Services\Tokens\TokenService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Create extends Component
{
    public int $step = 1;

    public string $name = '';

    public string $hostname = '';

    public string $server_type = '';

    public string $interval_seconds = '60';

    public string $notes = '';

    public bool $alerts_enabled = true;

    public function mount(): void
    {
        Gate::authorize('create', Server::class);
    }

    /**
     * Validate step one and advance to the review screen.
     *
     * The wizard keeps the entered values in component state; nothing is
     * persisted until `create()` runs. An invalid submit stays on step one
     * with inline errors.
     */
    public function goToReview(): void
    {
        $this->validateStepOne();

        $this->step = 2;
    }

    public function back(): void
    {
        $this->step = 1;
    }

    /**
     * Create the server and its first token in one transaction, then hand the
     * plaintext to the reveal page through a one-request flash payload.
     *
     * The plaintext exists only in local variables. It is never assigned to a
     * public property, so it cannot leak through Livewire state or the wizard
     * markup.
     */
    public function create(): void
    {
        Gate::authorize('create', Server::class);

        // Validate against step one so any stale values (a duplicate name
        // created while the review was open) show inline on the form.
        $this->step = 1;
        $this->validateStepOne();

        try {
            [$server, $plain, $prefix] = DB::transaction(function (): array {
                $server = app(ServerService::class)->create([
                    'name' => $this->name,
                    'hostname' => $this->hostname,
                    'server_type' => $this->server_type,
                    'interval_seconds' => (int) $this->interval_seconds,
                    'alerts_enabled' => $this->alerts_enabled,
                    'notes' => trim($this->notes) === '' ? null : trim($this->notes),
                ], auth()->user());

                $issued = app(TokenService::class)->issue($server, auth()->user());

                return [$server, $issued->plain, $issued->token->token_prefix];
            });
        } catch (ValidationException $exception) {
            // A concurrent insert can pass step one and still fail the service
            // rules; surface it inline instead of returning a 500.
            $this->step = 1;
            $this->setErrorBag($exception->errors());

            return;
        } catch (QueryException $exception) {
            // The unique index on `servers.name` is the last line of defence
            // when two operators submit the same name at once.
            if (! in_array((string) $exception->getCode(), ['23000', '23505'], true)) {
                throw $exception;
            }

            $this->step = 1;
            $this->addError('name', 'A server with this name already exists.');

            return;
        }

        session()->flash('issued_token', [
            'server_id' => $server->getKey(),
            'token_cipher' => Crypt::encryptString($plain),
            'prefix' => $prefix,
        ]);

        $this->redirectRoute('servers.token-reveal', ['server' => $server]);
    }

    public function render(): View
    {
        $groups = Server::query()
            ->whereNotNull('server_type')
            ->where('server_type', '!=', '')
            ->distinct()
            ->orderBy('server_type')
            ->pluck('server_type')
            ->all();

        return view('livewire.servers.create', [
            'groups' => $groups,
            'intervals' => [10, 15, 30, 60, 120, 300, 600, 900, 1800, 3600],
        ]);
    }

    /**
     * The same field rules `ServerService::create()` enforces, so a value that
     * passes the wizard cannot be rejected by the service for a field issue.
     */
    private function validateStepOne(): void
    {
        foreach (['name', 'hostname', 'server_type', 'notes'] as $key) {
            $this->{$key} = trim($this->{$key});
        }

        $this->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('servers', 'name')],
            'hostname' => ['required', 'string', 'max:255'],
            'server_type' => ['nullable', 'string', 'max:100'],
            'interval_seconds' => ['required', 'integer', 'between:10,3600'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'alerts_enabled' => ['boolean'],
        ]);
    }
}
