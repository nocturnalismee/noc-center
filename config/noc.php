<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Realtime Updates
    |--------------------------------------------------------------------------
    |
    | Reverb is an enhancement, not a hard runtime dependency. Shared hosting
    | and emergency rollback both set NOC_REALTIME=false and continue through
    | the existing Livewire polling paths.
    |
    */

    'realtime' => [
        'enabled' => filter_var(env('NOC_REALTIME', false), FILTER_VALIDATE_BOOLEAN),
        'debounce_ms' => (int) env('NOC_REALTIME_DEBOUNCE_MS', 750),
    ],

];
