<?php

namespace App\Models\Concerns;

use App\Support\Barcode;

/**
 * Assigns the record an EAN-13 code after creation (the ID is known only after saving).
 *
 * The model must define a barcodeKey() method returning a key from config/barcode.php.
 * The "barcode" column must be nullable.
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
     * Prefix (from .env) + zero-padded ID + check digit.
     */
    public function generateBarcode(): string
    {
        return Barcode::ean13($this->barcodeKey(), (int) $this->getKey());
    }
}
