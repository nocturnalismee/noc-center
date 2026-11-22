<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class IngestController extends Controller
{
    /**
     * Acknowledge an agent sample.
     *
     * The request has already passed the body-size, rate-limit and bearer
     * token middleware. Payload validation and storage belong to a later
     * task; this endpoint only confirms receipt.
     */
    public function __invoke(): JsonResponse
    {
        return response()
            ->json(['status' => 'accepted'], 202)
            ->header('Cache-Control', 'no-store');
    }
}
