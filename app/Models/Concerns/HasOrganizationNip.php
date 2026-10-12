<?php

namespace App\Models\Concerns;

use App\Models\Organization;
use Illuminate\Validation\ValidationException;

/**
 * NIP rule for headquarters and branches:
 * - they may have the same NIP as their organization (the NIP column is not unique),
 * - they must not have another organization's NIP.
 */
trait HasOrganizationNip
{
    protected static function bootHasOrganizationNip(): void
    {
        static::saving(function (self $model) {
            if (blank($model->NIP)) {
                return;
            }

            $usedByOtherOrganization = Organization::query()
                ->where('NIP', $model->NIP)
                ->where('id', '!=', $model->organization_id)
                ->exists();

            if ($usedByOtherOrganization) {
                throw ValidationException::withMessages([
                    'NIP' => 'Ten NIP należy do innej organizacji.',
                ]);
            }
        });
    }

    /**
     * The headquarters' own NIP, or the organization's NIP if the headquarters has none.
     */
    public function effectiveNip(): ?string
    {
        return $this->NIP ?: $this->organization?->NIP;
    }
}
