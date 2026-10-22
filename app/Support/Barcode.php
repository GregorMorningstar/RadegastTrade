<?php

namespace App\Support;

use App\Models\Offer;
use App\Models\Order;
use App\Models\Organization;
use App\Models\ShopBranch;
use App\Models\ShopHeadquarter;
use App\Models\Task;
use App\Models\User;
use App\Models\WarehouseBranch;
use App\Models\WarehouseHeadquarter;
use InvalidArgumentException;

class Barcode
{
    /**
     * Models looked up by the code prefix (key matching config/barcode.php).
     *
     * @var array<string, class-string>
     */
    private const MODELS = [
        'user' => User::class,
        'organization' => Organization::class,
        'warehouse_headquarter' => WarehouseHeadquarter::class,
        'shop_headquarter' => ShopHeadquarter::class,
        'warehouse_branch' => WarehouseBranch::class,
        'shop_branch' => ShopBranch::class,
        'task' => Task::class,
        'offer' => Offer::class,
        'order' => Order::class,
    ];

    /**
     * Prefix (4 digits from the 2000-2999 range) configured for the given entity type.
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
     * All configured prefixes, excluding the own-products prefix.
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
     * Builds a valid EAN-13: prefix + zero-padded ID + check digit.
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
     * EAN-13 check digit for 12 digits.
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
     * Whether the code has a valid length and EAN-13 check digit.
     */
    public static function isValidEan13(string $code): bool
    {
        return preg_match('/^\d{13}$/', $code) === 1
            && self::checkDigit(substr($code, 0, 12)) === (int) $code[12];
    }

    /**
     * Whether the code starts with a prefix reserved for system entities (not for products).
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
     * Recognizes the code type by its prefix and returns the matching system record (user,
     * organization, headquarters) or null. For codes without a system prefix (goods from
     * manufacturers) it returns null – looking those up belongs to the products module.
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
