<?php

namespace App\Models\Builders;

use Illuminate\Database\Eloquent\Builder;
use LogicException;

/**
 * Eloquent builder that enforces the append-only contract for audit events.
 *
 * Model events do not fire for mass updates/deletes, so the guard lives on
 * the builder itself.
 */
class AuditEventBuilder extends Builder
{
    /**
     * @param  array<string, mixed>  $values
     */
    public function update(array $values)
    {
        throw new LogicException('Audit events are append-only and cannot be updated.');
    }

    public function delete()
    {
        throw new LogicException('Audit events are append-only and cannot be deleted.');
    }
}
