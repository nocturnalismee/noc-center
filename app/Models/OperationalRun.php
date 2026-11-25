<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OperationalRun extends Model
{
    protected $fillable = [
        'task_key',
        'started_at',
        'finished_at',
        'succeeded_at',
        'status',
        'summary',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'succeeded_at' => 'immutable_datetime',
            'summary' => 'array',
        ];
    }
}
