<?php

namespace App\Support;

use App\Models\Organization;
use App\Models\ShopHeadquarter;
use App\Models\User;
use App\Models\WarehouseHeadquarter;
use InvalidArgumentException;

class Barcode
{
    /**
     * Modele wyszukiwane po prefiksie kodu (klucz zgodny z config/barcode.php).
     *
     * @var array<string, class-string>
     */
    private const MODELS = [
        'user' => User::class,
        'organization' => Organization::class,
        'warehouse_headquarter' => WarehouseHeadquarter::class,
        'shop_headquarter' => ShopHeadquarter::class,
    ];

    /**
     * Prefiks (4 cyfry z zakresu 2000-2999) skonfigurowany dla danego rodzaju encji.
     */
    public static function prefix(string $key): string
    {
        $prefix = (string) config("barcode.prefixes.{$key}");

        if (! preg_match('/^2\d{3}$/', $prefix)) {
            throw new InvalidArgumentException(
                "Prefiks kodu kreskowego [{$key}] musi mieć 4 cyfry z zakresu 2000-2999 (zarezerwowanego przez GS1 na użytek wewnętrzny)."
            );
        }

        return $prefix;
    }

    /**
     * Wszystkie skonfigurowane prefiksy, bez prefiksu produktów własnych.
     *
     * @return array<int, string>
     */
    public static function entityPrefixes(): array
    {
        return collect(array_keys(config('barcode.prefixes')))
            ->reject(fn (string $key) => $key === 'product')
            ->map(fn (string $key) => self::prefix($key))
            ->values()
            ->all();
    }

    /**
     * Buduje poprawny EAN-13: prefiks + ID dopełnione zerami + cyfra kontrolna.
     */
    public static function ean13(string $key, int $id): string
    {
        $prefix = self::prefix($key);
        $bodyLength = 12 - strlen($prefix);
        $body = str_pad((string) $id, $bodyLength, '0', STR_PAD_LEFT);

        if (strlen($body) > $bodyLength) {
            throw new InvalidArgumentException("ID [{$id}] jest za duże dla kodu EAN-13 z prefiksem [{$prefix}].");
        }

        $base = $prefix.$body;

        return $base.self::checkDigit($base);
    }

    /**
     * Cyfra kontrolna EAN-13 dla 12 cyfr.
     */
    public static function checkDigit(string $base): int
    {
        $sum = 0;
        foreach (str_split($base) as $i => $digit) {
            $sum += (int) $digit * ($i % 2 === 0 ? 1 : 3);
        }

        return (10 - $sum % 10) % 10;
    }

    /**
     * Czy kod ma poprawną długość i cyfrę kontrolną EAN-13.
     */
    public static function isValidEan13(string $code): bool
    {
        return preg_match('/^\d{13}$/', $code) === 1
            && self::checkDigit(substr($code, 0, 12)) === (int) $code[12];
    }

    /**
     * Czy kod zaczyna się od prefiksu zarezerwowanego dla encji systemu (nie dla produktów).
     */
    public static function usesEntityPrefix(string $code): bool
    {
        foreach (self::entityPrefixes() as $prefix) {
            if (str_starts_with($code, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Rozpoznaje rodzaj kodu po prefiksie i zwraca znaleziony rekord systemu (użytkownik,
     * organizacja, siedziba) albo null. Dla kodów bez prefiksu systemowego (towary od
     * producentów) zwraca null – ich wyszukiwanie należy do modułu produktów.
     */
    public static function resolve(string $code): ?object
    {
        foreach (self::MODELS as $key => $model) {
            if (str_starts_with($code, self::prefix($key))) {
                return $model::where('barcode', $code)->first();
            }
        }

        return null;
    }
}
