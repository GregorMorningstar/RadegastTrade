<?php

namespace App\Models;

use App\Models\Concerns\GeneratesBarcode;
use App\Models\Concerns\HasOrganizationNip;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Shop branch: belongs to a shop headquarters and to an organization (foreign key from the invitation).
 */
#[Fillable([
    'organization_id',
    'shop_headquarter_id',
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
class ShopBranch extends Model
{
    use GeneratesBarcode, HasOrganizationNip;

    protected function barcodeKey(): string
    {
        return 'shop_branch';
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<ShopHeadquarter, $this>
     */
    public function headquarter(): BelongsTo
    {
        return $this->belongsTo(ShopHeadquarter::class, 'shop_headquarter_id');
    }

    /**
     * Leaders assigned to the branch.
     *
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * @return HasMany<OrganizationInvitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(OrganizationInvitation::class);
    }
}
