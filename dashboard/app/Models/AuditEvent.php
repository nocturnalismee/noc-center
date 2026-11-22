<?php

namespace App\Models;

use App\Models\Builders\AuditEventBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
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
            'target_id' => 'string',
            'meta_json' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (AuditEvent $event): void {
            if ($event->exists) {
                throw new LogicException('Audit events are append-only and cannot be updated.');
            }
        });

        static::deleting(function (): void {
            throw new LogicException('Audit events are append-only and cannot be deleted.');
        });
    }

    /**
     * @param  Builder  $query
     */
    public function newEloquentBuilder($query): AuditEventBuilder
    {
        return new AuditEventBuilder($query);
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
