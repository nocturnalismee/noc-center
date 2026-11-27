<?php

namespace App\Services;

use App\Fleet\FleetState;
use App\Models\Alert;
use Illuminate\Support\Facades\Cache;

class FleetSummary
{
    public const CACHE_KEY = 'fleet.summary';

    public const CACHE_SECONDS = 20;

    public function __construct(private readonly SettingsRepository $settings) {}

    /**
     * @return array{total:int,online:int,attention:int,hot:int,gaps:int,open_alerts:int,samples_per_minute:int}
     */
    public function summary(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, function (): array {
            $openAlerts = Alert::query()
                ->where('status', 'open')
                ->count();

            return [
                ...FleetState::summary($this->settings, now()->utc()),
                'open_alerts' => $openAlerts,
            ];
        });
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
