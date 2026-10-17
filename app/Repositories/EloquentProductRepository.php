<?php

namespace App\Repositories;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Repositories\Contracts\ProductRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class EloquentProductRepository implements ProductRepositoryInterface
{
    public function isReady(): bool
    {
        return Schema::hasTable('products') && Schema::hasColumn('products', 'organization_id');
    }

    public function categories(int $organizationId): Collection
    {
        if (! Schema::hasTable('product_categories') || ! Schema::hasColumn('product_categories', 'organization_id')) {
            return new Collection;
        }

        $categories = ProductCategory::query()->where('organization_id', $organizationId)->orderBy('name')->get();

        $counts = $this->isReady() && Schema::hasColumn('products', 'category_id')
            ? Product::query()->where('organization_id', $organizationId)->whereNotNull('category_id')->selectRaw('category_id, count(*) as total')->groupBy('category_id')->pluck('total', 'category_id')
            : collect();

        return $categories->each(fn (ProductCategory $category) => $category->setAttribute('products_count', (int) ($counts[$category->id] ?? 0)));
    }

    public function paginate(int $organizationId, array $categoryIds, ?string $search, string $sort, int $page, int $perPage): LengthAwarePaginator
    {
        if (! $this->isReady()) {
            return new Paginator([], 0, $perPage, $page);
        }

        $query = Product::query()
            ->where('organization_id', $organizationId)
            ->when($categoryIds !== [] && Schema::hasColumn('products', 'category_id'), fn (Builder $query) => $query->whereIn('category_id', $categoryIds))
            ->when($search !== null && $search !== '', fn (Builder $query) => $query->where(fn (Builder $match) => $match
                ->where('name', 'like', '%'.addcslashes($search, '%_\\').'%')
                ->orWhere('barcode', 'like', '%'.addcslashes($search, '%_\\').'%')));

        match ($sort) {
            'name_desc' => $query->orderByDesc('name'),
            'newest' => $query->orderByDesc('id'),
            default => $query->orderBy('name'),
        };

        return $query->paginate($perPage, ['*'], 'page', $page);
    }

    public function count(int $organizationId): int
    {
        return $this->isReady() ? Product::query()->where('organization_id', $organizationId)->count() : 0;
    }
}
