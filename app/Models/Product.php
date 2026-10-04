<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'barcode'])]
class Product extends Model
{
    /**
     * Prefiks EAN-13 dla produktów własnych (bez EAN producenta).
     * Zakres 20-29 jest zarezerwowany przez GS1 na użytek wewnętrzny.
     */
    public const INTERNAL_BARCODE_PREFIX = '21';
}
