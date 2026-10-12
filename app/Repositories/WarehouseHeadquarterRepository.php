<?php

namespace App\Repositories;

use App\Models\WarehouseHeadquarter;

class WarehouseHeadquarterRepository extends EloquentHeadquarterRepository
{
    protected function model(): string
    {
        return WarehouseHeadquarter::class;
    }
}
