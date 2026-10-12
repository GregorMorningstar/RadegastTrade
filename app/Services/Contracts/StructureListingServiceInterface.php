<?php

namespace App\Services\Contracts;

use App\Enums\HeadquarterType;

interface StructureListingServiceInterface
{
    /**
     * @return list<array<string, mixed>>
     */
    public function headquarters(HeadquarterType $type): array;

    /**
     * Shop or warehouse branches.
     *
     * @return list<array<string, mixed>>
     */
    public function branches(HeadquarterType $type): array;
}
