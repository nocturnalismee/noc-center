<?php

namespace App\Http\Controllers;

use App\Models\Server;
use App\Services\Metrics\MetricSeriesRepository;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * JSON chart series for one server (spec §9.2).
 *
 * The endpoint is read-only and private: viewers may read any server they can
 * view, and the response is cacheable for 15 seconds only by the client.
 */
class ServerSeriesController extends Controller
{
    use AuthorizesRequests;

    public function __invoke(
        Request $request,
        Server $server,
        MetricSeriesRepository $series,
    ): JsonResponse {
        $this->authorize('view', $server);

        $validated = $request->validate([
            'metric' => ['required', 'string', Rule::in(array_keys(config('metrics.metrics', [])))],
            'range' => ['required', 'string', Rule::in(array_keys(config('metrics.ranges', [])))],
        ]);

        return response()
            ->json($series->series($server, $validated['metric'], $validated['range']))
            ->header('Cache-Control', 'private, max-age=15');
    }
}
