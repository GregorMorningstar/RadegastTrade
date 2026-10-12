<?php

namespace App\Repositories;

use App\Models\ShopBranch;

class ShopBranchRepository extends EloquentBranchRepository
{
    protected function model(): string
    {
        return ShopBranch::class;
    }

    protected function headquarterColumn(): string
    {
        return 'shop_headquarter_id';
    }
}
