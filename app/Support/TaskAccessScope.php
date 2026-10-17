<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * What a person may see among the tasks of ONE organization. Built only by the service from the signed-in user (never
 * from request data); the organization is mandatory, so a query can never lose its organization filter.
 */
final class TaskAccessScope
{
    /**
     * @param  bool  $all  every task of the organization (the organization owner)
     * @param  int  $userId  the viewer: always sees what they created
     * @param  list<int>  $userIds  people whose tasks the viewer sees (themselves and the people below them)
     * @param  list<int>  $shopHeadquarterIds
     * @param  list<int>  $warehouseHeadquarterIds
     * @param  list<int>  $shopBranchIds
     * @param  list<int>  $warehouseBranchIds
     */
    public function __construct(
        public readonly int $organizationId,
        public readonly int $userId,
        public readonly bool $all = false,
        public readonly array $userIds = [],
        public readonly array $shopHeadquarterIds = [],
        public readonly array $warehouseHeadquarterIds = [],
        public readonly array $shopBranchIds = [],
        public readonly array $warehouseBranchIds = [],
    ) {
        if ($organizationId <= 0) {
            throw new InvalidArgumentException('Brak poprawnego kontekstu organizacji.');
        }
    }
}
