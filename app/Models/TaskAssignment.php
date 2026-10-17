<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Who a task is for: the whole organization, a headquarters, a branch or one person. A unit covers everyone below it.
 */
#[Fillable([
    'task_id',
    'assignee_type',
    'user_id',
    'shop_headquarter_id',
    'warehouse_headquarter_id',
    'shop_branch_id',
    'warehouse_branch_id',
])]
class TaskAssignment extends Model
{
    public const ORGANIZATION = 'organization';

    public const HEADQUARTERS = 'headquarters';

    public const BRANCH = 'branch';

    public const USER = 'user';

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<ShopHeadquarter, $this>
     */
    public function shopHeadquarter(): BelongsTo
    {
        return $this->belongsTo(ShopHeadquarter::class);
    }

    /**
     * @return BelongsTo<WarehouseHeadquarter, $this>
     */
    public function warehouseHeadquarter(): BelongsTo
    {
        return $this->belongsTo(WarehouseHeadquarter::class);
    }

    /**
     * @return BelongsTo<ShopBranch, $this>
     */
    public function shopBranch(): BelongsTo
    {
        return $this->belongsTo(ShopBranch::class);
    }

    /**
     * @return BelongsTo<WarehouseBranch, $this>
     */
    public function warehouseBranch(): BelongsTo
    {
        return $this->belongsTo(WarehouseBranch::class);
    }
}
