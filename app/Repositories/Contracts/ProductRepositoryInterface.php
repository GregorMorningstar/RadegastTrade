<?php

namespace App\Repositories\Contracts;

use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface ProductRepositoryInterface
{
    /**
     * Whether the catalogue can be read: the tables exist and the products carry their organization (without it a
     * product could not be told apart between organizations, so nothing is ever listed).
     */
    public function isReady(): bool;

    /**
     * Categories of ONE organization by name, each with the number of its own products (products_count).
     *
     * @return Collection<int, ProductCategory>
     */
    public function categories(int $organizationId): Collection;

    /**
     * Products of ONE organization, optionally only those of the given categories and matching the search text (name
     * or barcode), sorted by "name", "name_desc" or "newest".
     *
     * @param  list<int>  $categoryIds  empty: every category (and products without one)
     * @return LengthAwarePaginator<int, Product>
     */
    public function paginate(int $organizationId, array $categoryIds, ?string $search, string $sort, int $page, int $perPage): LengthAwarePaginator;

    /** Number of products of the organization. */
    public function count(int $organizationId): int;
}
