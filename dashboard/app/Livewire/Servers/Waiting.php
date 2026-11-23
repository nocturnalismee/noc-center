<?php

namespace App\Livewire\Servers;

use App\Models\Server;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('layouts.app')]
class Waiting extends Component
{
    /**
     * How long the page keeps polling before it stops and shows help.
     */
    public const TIMEOUT_SECONDS = 600;

    #[Locked]
    public Server $server;

    #[Locked]
    public int $startedAt = 0;

    public int $elapsed = 0;

    public bool $connected = false;

    public function mount(Server $server): void
    {
        Gate::authorize('manageTokens', Server::class);

        $this->server = $server;
        $this->startedAt = now()->getTimestamp();

        $this->sync();
    }

    /**
     * Poll the server for its first check-in and advance the elapsed clock.
     *
     * Polling is bounded: once the server is seen, or once the ten-minute
     * window elapses, `polling()` turns false and `wire:poll` is no longer
     * rendered, so the page never polls forever.
     */
    public function poll(): void
    {
        Gate::authorize('manageTokens', Server::class);

        $this->sync();
    }

    public function polling(): bool
    {
        return ! $this->connected && $this->elapsed < self::TIMEOUT_SECONDS;
    }

    public function elapsedLabel(): string
    {
        return sprintf('%02d:%02d', intdiv($this->elapsed, 60), $this->elapsed % 60);
    }

    public function timedOut(): bool
    {
        return ! $this->connected && $this->elapsed >= self::TIMEOUT_SECONDS;
    }

    public function render(): View
    {
        return view('livewire.servers.waiting', [
            'appUrl' => (string) config('app.url'),
            'polling' => $this->polling(),
        ]);
    }

    private function sync(): void
    {
        $this->server->refresh();

        $this->elapsed = min(
            max(0, now()->getTimestamp() - $this->startedAt),
            self::TIMEOUT_SECONDS,
        );

        if ($this->server->last_seen_at !== null) {
            $this->connected = true;
        }
    }
}
