<?php

namespace App\Support;

use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class Barcode
{
    /**
     * Rozpoznaje typ kodu po prefiksie i zwraca użytkownika lub produkt (albo null).
     */
    public static function resolve(string $code): User|Product|null
    {
        $model = str_starts_with($code, User::BARCODE_PREFIX) ? User::class : Product::class;

        /** @var Model $model */
        return $model::where('barcode', $code)->first();
    }

    /**
     * Czy kod ma poprawną długość i cyfrę kontrolną EAN-13.
     */
    public static function isValidEan13(string $code): bool
    {
        if (! preg_match('/^\d{13}$/', $code)) {
            return false;
        }

        $sum = 0;
        foreach (str_split(substr($code, 0, 12)) as $i => $digit) {
            $sum += (int) $digit * ($i % 2 === 0 ? 1 : 3);
        }

        return (10 - $sum % 10) % 10 === (int) $code[12];
    }
}
