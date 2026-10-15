<?php

namespace App\Models;

use App\Enums\UnitPosition;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Who manages and who works in a unit: a shop / warehouse headquarters or branch.
 * One row per person (a person works in at most one unit); exactly one of the four unit columns is set.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $user_id
 * @property UnitPosition $position
 */
#[Fillable([
    'organization_id',
    'user_id',
    'shop_headquarter_id',
    'warehouse_headquarter_id',
    'shop_branch_id',
    'warehouse_branch_id',
    'position',
])]
class UnitMembership extends Model
{
    protected function casts(): array
    {
        return [
            'position' => UnitPosition::class,
        ];
    }
}
