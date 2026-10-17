<?php

namespace App\Services\Contracts;

use App\Models\User;

interface ProductServiceInterface
{
    /**
     * The catalogue of the person's organization for the product list: a page of products, the categories with the
     * number of products (a flat list with parent_id: the page builds the tree) and the filters in force.
     *
     * Only the owner of the organization and the people of its shops and warehouses may look (403 for anyone else, also
     * for an account without an organization); the organization always comes from the account, never from the
     * request. A category of another organization does not exist for the person (the filter is ignored).
     *
     * @param  array{category?: int|string|null, q?: string|null, sort?: string|null, page?: int|string|null}  $filters
     * @return array{
     *     ready: bool,
     *     products: array{data: list<array<string, mixed>>, current_page: int, last_page: int, per_page: int, total: int},
     *     categories: list<array{id: int, name: string, parent_id: ?int, count: int, total: int}>,
     *     total_products: int,
     *     filters: array{category: ?int, q: ?string, sort: string}
     * }
     */
    public function catalog(User $actor, array $filters = []): array;
}
