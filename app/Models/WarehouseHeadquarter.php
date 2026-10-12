<?php

namespace App\Models;

use App\Models\Concerns\GeneratesBarcode;
use App\Models\Concerns\HasOrganizationNip;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'organization_id',
    'name',
    'barcode',
    'NIP',
    'email',
    'phone',
    'street',
    'building_number',
    'apartment_number',
    'postal_code',
    'city',
    'country',
    'is_active',
])]
class WarehouseHeadquarter extends Model
{
    use GeneratesBarcode, HasOrganizationNip;

    protected function barcodeKey(): string
    {
        return 'warehouse_headquarter';
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * The organization that owns this headquarters (exactly one).
     *
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
