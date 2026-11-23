<?php

namespace App\Services\Ingest;

use App\Models\MetricSample;
use App\Models\Server;

/**
 * Outcome of one ingest store call.
 *
 * `created` is false when the `(server_id, collected_at)` key already existed:
 * the response is still a 202 and the server state was still advanced, but no
 * second sample or detail row was written. `sample` is the stored (or already
 * existing) sample and `server` is the refreshed server model.
 *
 * Task 8 adds `$issues` once `IssueSet` exists; until then the stored server
 * keeps `health = ok` and `issue_codes = []`.
 */
final readonly class IngestResult
{
    public function __construct(
        public bool $created,
        public MetricSample $sample,
        public Server $server,
    ) {
        //
    }
}
