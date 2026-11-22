<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class AuditEvent extends Model
{
    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'action',
        'target_type',
        'target_id',
        'ip',
        'user_agent',
        'meta_json',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'meta_json' => 'array',
        ];
    }

    public function update(array $attributes = [], array $options = [])
    {
        throw new LogicException('Audit events are append-only and cannot be updated.');
    }

    public function delete()
    {
        throw new LogicException('Audit events are append-only and cannot be deleted.');
    }
}
