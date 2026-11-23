<?php

namespace App\Livewire\Activity;

use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

/**
 * Read-only audit trail (spec §7.10, §12).
 *
 * Administrators and operators may read; viewers may not. There is no edit,
 * update or delete path: `AuditEvent` and its builder reject mutation and the
 * page exposes no actions.
 */
#[Layout('layouts.app')]
class Index extends Component
{
    use WithPagination;

    #[Url(except: '')]
    public string $action = '';

    #[Url(except: '')]
    public string $actorId = '';

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    public function mount(): void
    {
        Gate::authorize('viewAny', AuditEvent::class);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['action', 'actorId', 'from', 'to'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->action = '';
        $this->actorId = '';
        $this->from = '';
        $this->to = '';
        $this->resetPage();
    }

    public function render(): View
    {
        $from = $this->dayBoundary($this->from, 'start');
        $to = $this->dayBoundary($this->to, 'end');

        $query = AuditEvent::query()
            ->with('user')
            ->when($this->action !== '', fn (Builder $query) => $query->where('action', $this->action))
            ->when($this->actorId !== '', fn (Builder $query) => $query->where('user_id', (int) $this->actorId))
            ->when($from !== null, fn (Builder $query) => $query->where('created_at', '>=', $from))
            ->when($to !== null, fn (Builder $query) => $query->where('created_at', '<=', $to))
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        return view('livewire.activity.index', [
            'events' => $query->paginate(25),
            'actions' => AuditEvent::query()
                ->select('action')
                ->distinct()
                ->orderBy('action')
                ->pluck('action')
                ->all(),
            'actors' => User::query()->orderBy('name')->pluck('name', 'id'),
        ]);
    }

    /**
     * Parse a strict `Y-m-d` filter as an Asia/Jakarta calendar day and
     * convert its boundary to UTC for the query. A crafted URL must fall back
     * to "no date filter" instead of throwing a 500 from Carbon.
     */
    private function dayBoundary(string $date, string $boundary): ?Carbon
    {
        if (trim($date) === '') {
            return null;
        }

        try {
            $parsed = Carbon::createFromFormat('!Y-m-d', $date, 'Asia/Jakarta');
        } catch (Throwable) {
            return null;
        }

        if ($parsed === false) {
            return null;
        }

        return ($boundary === 'start' ? $parsed->startOfDay() : $parsed->endOfDay())->utc();
    }
}
