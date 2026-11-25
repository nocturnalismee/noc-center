<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class SampleStored implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public bool $afterCommit = true;

    public function __construct(
        public string $serverId,
        public string $sampleId,
        public string $collectedAt,
        public string $health,
        public string $status,
    ) {
        //
    }

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('fleet'),
            new PrivateChannel('server.'.$this->serverId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'SampleStored';
    }

    /**
     * @return array<string, string>
     */
    public function broadcastWith(): array
    {
        return [
            'server_id' => $this->serverId,
            'sample_id' => $this->sampleId,
            'collected_at' => $this->collectedAt,
            'health' => $this->health,
            'status' => $this->status,
        ];
    }
}
