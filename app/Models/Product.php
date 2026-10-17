<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A product of the catalogue of an organization.
 *
 * @property int $id
 * @property int|null $organization_id
 * @property int|null $category_id
 * @property string $name
 * @property string $barcode
 */
#[Fillable(['category_id', 'name', 'barcode', 'description', 'price'])]
class Product extends Model
{
    protected function casts(): array
    {
        return ['price' => 'decimal:2'];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<ProductCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }
}
