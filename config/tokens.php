<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Agent token rotation grace
    |--------------------------------------------------------------------------
    |
    | After a rotation the previous token stays valid for this many minutes so
    | an operator can install the new token without a collection gap. The
    | default is one day; `0` expires the old token immediately. Changing it
    | affects new rotations only; existing expiries are never extended.
    |
    */

    'grace_minutes' => (int) env('NOC_TOKEN_GRACE_MINUTES', 1440),

];
