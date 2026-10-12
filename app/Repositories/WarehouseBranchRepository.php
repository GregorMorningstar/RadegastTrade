<?php

namespace App\Repositories;

use App\Models\WarehouseBranch;

class WarehouseBranchRepository extends EloquentBranchRepository
{
    protected function model(): string
    {
        return WarehouseBranch::class;
    }

    protected function headquarterColumn(): string
    {
        return 'warehouse_headquarter_id';
    }
}
