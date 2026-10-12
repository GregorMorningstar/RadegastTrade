<?php

namespace App\Models\Concerns;

use App\Support\Barcode;

/**
 * Nadaje rekordowi kod EAN-13 po utworzeniu (ID jest znane dopiero po zapisie).
 *
 * Model musi zdefiniować metodę barcodeKey() zwracającą klucz z config/barcode.php.
 * Kolumna "barcode" musi być nullable.
 */
trait GeneratesBarcode
{
    abstract protected function barcodeKey(): string;

    protected static function bootGeneratesBarcode(): void
    {
        static::created(function (self $model) {
            if (blank($model->barcode)) {
                $model->barcode = $model->generateBarcode();
                $model->saveQuietly();
            }
        });
    }

    /**
     * Prefiks (z .env) + ID dopełnione zerami + cyfra kontrolna.
     */
    public function generateBarcode(): string
    {
        return Barcode::ean13($this->barcodeKey(), (int) $this->getKey());
    }
}
