<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MetricDetail extends Model
{
    public const UPDATED_AT = null;

    protected $primaryKey = 'sample_id';

    public $incrementing = false;

    protected $keyType = 'int';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'sample_id',
        'panel_metrics',
        'extensions',
        'size_bytes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'panel_metrics' => 'array',
            'extensions' => 'array',
            'size_bytes' => 'integer',
        ];
    }

    public function sample(): BelongsTo
    {
        return $this->belongsTo(MetricSample::class, 'sample_id');
    }
}
