<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A row of a table that the connector of a unit sent. The organization and the unit are set by the backend from the API
 * key, never from the data.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $unit_ref
 * @property string $table_name
 * @property string $external_id
 * @property array<string, mixed> $data
 */
#[Fillable(['table_name', 'external_id', 'data', 'synced_at'])]
class SyncedRow extends Model
{
    protected function casts(): array
    {
        return [
            'data' => 'array',
            'synced_at' => 'datetime',
        ];
    }
}
