<?php

namespace App\Services\Contracts;

use App\Enums\HeadquarterType;
use App\Models\User;

interface StructureListingServiceInterface
{
    /**
     * Only the platform administrator may list the units of every organization (403 for anyone else, also when the
     * route middleware is missing); at most 500 rows.
     *
     * @return list<array<string, mixed>>
     */
    public function headquarters(User $viewer, HeadquarterType $type): array;

    /**
     * Shop or warehouse branches.
     *
     * @return list<array<string, mixed>>
     */
    public function branches(User $viewer, HeadquarterType $type): array;
}
