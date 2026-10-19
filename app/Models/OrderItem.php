<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A position of an order: a copy of the offer position (name, unit, price, tax) with the amounts. */
class OrderItem extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:3',
            'unit_price' => 'decimal:4',
            'vat_rate' => 'decimal:2',
            'line_net' => 'decimal:2',
            'line_tax' => 'decimal:2',
            'line_gross' => 'decimal:2',
        ];
    }
}
