<?php

namespace App\Services\Contracts;

use App\Enums\HeadquarterType;
use App\Models\Organization;
use App\Models\User;

interface OrganizationMembersServiceInterface
{
    /**
     * Breakdown of one organization into units for the platform staff (administrator or manager);
     * everyone else gets 403. The organization owners come first, then a page of headquarters of the
     * chosen type (5 per page), each with its managers and its branches with their people.
     * A search text narrows the headquarters to those matching it by their own data, their branches or their people.
     *
     * @return array{
     *     owners: list<array<string, mixed>>,
     *     group: string,
     *     headquarters: array{data: list<array<string, mixed>>, current_page: int, last_page: int, total: int}
     * }
     */
    public function structure(User $viewer, Organization $organization, HeadquarterType $type, ?string $search = null, int $perPage = 5): array;

    /**
     * A page of headquarters of one type from all organizations (every one with its organization name),
     * each with its managers and its branches with their people. Same roles as above, everyone else gets 403.
     *
     * @return array{data: list<array<string, mixed>>, current_page: int, last_page: int, total: int}
     */
    public function headquarters(User $viewer, HeadquarterType $type, ?string $search = null, int $perPage = 5): array;

    /**
     * The same tree for the owner of an organization: only the headquarters of their own organization
     * (taken from their account, never from the request). Other roles get 403.
     *
     * @return array{data: list<array<string, mixed>>, current_page: int, last_page: int, total: int}
     */
    public function organizationHeadquarters(User $owner, HeadquarterType $type, ?string $search = null, int $perPage = 5): array;

    /**
     * The tree of the manager's own headquarters (its contact, people, branches and their people). Only shop and
     * warehouse headquarters managers may call it, and only for the headquarters (and organization) of their account.
     *
     * @return array{data: list<array<string, mixed>>, current_page: int, last_page: int, total: int}
     */
    public function ownHeadquarters(User $manager): array;
}
