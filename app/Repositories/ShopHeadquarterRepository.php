<?php

namespace App\Repositories;

use App\Models\ShopHeadquarter;

class ShopHeadquarterRepository extends EloquentHeadquarterRepository
{
    protected function model(): string
    {
        return ShopHeadquarter::class;
    }
}
