<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Repositories\Contracts\ProductRepositoryInterface;
use App\Services\Contracts\ProductServiceInterface;
use Illuminate\Support\Facades\Gate;

/**
 * Read-only product catalogue of the organization, with its categories and filters.
 */
class ProductService implements ProductServiceInterface
{
    public const PER_PAGE = 12;

    private const SORTS = ['name', 'name_desc', 'newest'];

    public function __construct(private readonly ProductRepositoryInterface $products)
    {
        //
    }

    public function catalog(User $actor, array $filters = []): array
    {
        Gate::forUser($actor)->authorize('viewAny', Product::class);

        $organizationId = (int) $actor->organization_id;

        $categories = $this->products->categories($organizationId);
        $categoryId = $this->knownCategory($categories->pluck('id')->all(), $filters['category'] ?? null);
        $search = isset($filters['q']) ? mb_substr(trim((string) $filters['q']), 0, 100) : null;
        $sort = in_array($filters['sort'] ?? null, self::SORTS, true) ? $filters['sort'] : 'name';

        $page = $this->products->paginate(
            $organizationId,
            $categoryId === null ? [] : $this->withDescendants($categories->all(), $categoryId),
            $search === '' ? null : $search,
            $sort,
            max(1, (int) ($filters['page'] ?? 1)),
            self::PER_PAGE,
        );

        return [
            'ready' => $this->products->isReady(),
            'products' => [
                'data' => $page->getCollection()->map(fn (Product $product) => $this->product($product, $categories->firstWhere('id', $product->getAttribute('category_id'))))->values()->all(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
            'categories' => $categories->map(fn (ProductCategory $category) => [
                'id' => $category->id,
                'name' => $category->name,
                'parent_id' => $category->parent_id,
                'count' => (int) $category->getAttribute('products_count'),
                'total' => collect($this->withDescendants($categories->all(), $category->id))->sum(fn (int $id) => (int) $categories->firstWhere('id', $id)?->getAttribute('products_count')),
            ])->values()->all(),
            'total_products' => $this->products->count($organizationId),
            'filters' => ['category' => $categoryId, 'q' => $search === '' ? null : $search, 'sort' => $sort],
        ];
    }

    /**
     * @param  list<int>  $known  categories of the organization
     */
    private function knownCategory(array $known, mixed $requested): ?int
    {
        $id = is_numeric($requested) ? (int) $requested : null;

        return $id !== null && in_array($id, $known, true) ? $id : null;
    }

    /**
     * The category and all the categories below it (a parent shows the products of its children too).
     *
     * @param  list<ProductCategory>  $categories
     * @return list<int>
     */
    private function withDescendants(array $categories, int $categoryId): array
    {
        $ids = [$categoryId];

        foreach ($categories as $category) {
            if ($category->parent_id === $categoryId) {
                $ids = [...$ids, ...$this->withDescendants($categories, $category->id)];
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return array<string, mixed>
     */
    private function product(Product $product, ?ProductCategory $category): array
    {
        return [
            'id' => $product->id,
            'name' => $product->name,
            'barcode' => $product->barcode,
            'description' => $product->getAttribute('description'),
            'price' => $product->getAttribute('price') !== null ? (float) $product->getAttribute('price') : null,
            'category' => $category?->name,
        ];
    }
}
