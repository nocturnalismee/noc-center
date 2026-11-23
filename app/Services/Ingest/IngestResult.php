<?php

namespace App\Services\Ingest;

use App\Issues\IssueSet;
use App\Models\MetricSample;
use App\Models\Server;

/**
 * Outcome of one ingest store call.
 *
 * `created` is false when the `(server_id, collected_at)` key already existed:
 * the response is still a 202 and the server state was still advanced, but no
 * second sample or detail row was written. `sample` is the stored (or already
 * existing) sample, `server` is the refreshed server model, and `issues`
 * describes the newest stored sample. For a replayed duplicate older than the
 * newest stored sample, `sample` is that newest sample and `issues` is its
 * current issue state, which the request did not change.
 *
 * Creating alerts from this set is Task 9; ingest only persists the state.
 */
final readonly class IngestResult
{
    public function __construct(
        public bool $created,
        public MetricSample $sample,
        public Server $server,
        public IssueSet $issues,
    ) {
        //
    }
}
