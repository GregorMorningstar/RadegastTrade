<?php

namespace App\Models\Concerns;

use App\Models\Organization;
use Illuminate\Validation\ValidationException;

/**
 * Zasada NIP dla siedzib i placówek:
 * - mogą mieć ten sam NIP co ich organizacja (kolumna NIP nie jest unikalna),
 * - nie mogą mieć NIP-u innej organizacji.
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
     * Własny NIP siedziby albo NIP organizacji, jeśli siedziba go nie podaje.
     */
    public function effectiveNip(): ?string
    {
        return $this->NIP ?: $this->organization?->NIP;
    }
}
