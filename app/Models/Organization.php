<?php

namespace App\Models;

use App\Models\Concerns\GeneratesBarcode;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

/**
 * Organizacja nadrzędna: może mieć wiele siedzib sklepów i hurtowni.
 *
 * NIP jest unikalny w skali organizacji (dwie organizacje nie mogą mieć tego samego),
 * natomiast jej siedziby mogą go współdzielić.
 */
#[Fillable([
    'name',
    'barcode',
    'NIP',
    'REGON',
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
class Organization extends Model
{
    use GeneratesBarcode;

    protected static function booted(): void
    {
        // NIP organizacji nie może być już używany przez siedzibę innej organizacji
        static::saving(function (Organization $organization) {
            if (blank($organization->NIP) || ! $organization->isDirty('NIP')) {
                return;
            }

            $ownId = $organization->getKey() ?? 0;

            $usedElsewhere = ShopHeadquarter::where('NIP', $organization->NIP)->where('organization_id', '!=', $ownId)->exists()
                || WarehouseHeadquarter::where('NIP', $organization->NIP)->where('organization_id', '!=', $ownId)->exists();

            if ($usedElsewhere) {
                throw ValidationException::withMessages([
                    'NIP' => 'Ten NIP jest już używany przez placówkę innej organizacji.',
                ]);
            }
        });
    }

    protected function barcodeKey(): string
    {
        return 'organization';
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Użytkownicy należący do organizacji (np. właściciele).
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

    /**
     * @return HasMany<ShopHeadquarter, $this>
     */
    public function shopHeadquarters(): HasMany
    {
        return $this->hasMany(ShopHeadquarter::class);
    }

    /**
     * @return HasMany<WarehouseHeadquarter, $this>
     */
    public function warehouseHeadquarters(): HasMany
    {
        return $this->hasMany(WarehouseHeadquarter::class);
    }
}
