<?php

namespace App\Http\Controllers;

use App\Models\Server;
use App\Services\Metrics\MetricSeriesRepository;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * JSON chart series for one server (spec §11, §12.3).
 *
 * The endpoint is read-only and private: viewers may read any server they can
 * view. Responses must not be cached by the browser because a realtime event
 * can refresh the chart immediately after a new sample is stored.
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
            ->header('Cache-Control', 'no-store, private');
    }
}
