<?php

namespace App\Database;

use App\Exceptions\InvalidTenantRequest;
use Illuminate\Database\Query\Builder;

/**
 * Protects persisted stock history from model-derived bulk mutations.
 * Inserts and reads remain available; corrections must append new movements.
 * Direct DB::table/raw SQL and database cascades are outside this application guard.
 */
final class InventoryMovementQueryBuilder extends Builder
{
    public function update(array $values)
    {
        // Also blocks query increments, decrements, and updateOrInsert's update path.
        $this->rejectMovementMutation();
    }

    public function updateFrom(array $values)
    {
        // Keep alternative grammar update paths append-only.
        $this->rejectMovementMutation();
    }

    public function delete($id = null)
    {
        // Includes Eloquent bulk delete and forceDelete paths.
        $this->rejectMovementMutation();
    }

    public function truncate()
    {
        // Model-derived truncation must not erase source history.
        $this->rejectMovementMutation();
    }

    public function upsert(array $values, array|string $uniqueBy, ?array $update = null)
    {
        // Upsert can rewrite an existing movement, so require ordinary append inserts instead.
        $this->rejectMovementMutation();
    }

    private function rejectMovementMutation(): never
    {
        // Reuse the existing localized domain error rather than a generic database exception.
        throw new InvalidTenantRequest(__('inventory.movement_immutable'));
    }
}
