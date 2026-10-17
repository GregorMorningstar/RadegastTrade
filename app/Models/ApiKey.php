<?php

namespace App\Models;

use App\Enums\HeadquarterType;
use App\Enums\UnitLevel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * The API key of one connector: a program that sends the data of a unit (a shop or warehouse headquarters or branch).
 * Only the keyed hash of the key is stored (never the key itself). The organization, the unit and the author are set
 * by the backend, not mass assigned.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $name
 * @property string $prefix
 * @property string $key_hash
 */
#[Fillable(['name', 'prefix', 'key_hash', 'last_used_at', 'last_used_ip', 'revoked_at'])]
#[Hidden(['key_hash', 'key_encrypted', 'removal_code_hash'])]
class ApiKey extends Model
{
    public const KEY_PREFIX = 'rtk_';

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
            'revealed_at' => 'datetime',
            // the key itself, encrypted with the application key (only a person who manages the unit can show it)
            'key_encrypted' => 'encrypted',
            'removal_code_expires_at' => 'datetime',
        ];
    }

    /** The hash that is stored and compared (keyed with the application key, so the table alone is of no use). */
    public static function hashOf(string $key): string
    {
        return hash_hmac('sha256', $key, (string) config('app.key'));
    }

    /**
     * The unit of the key: its type, level and id (the connector's unit).
     *
     * @return array{type: HeadquarterType, level: UnitLevel, id: int}
     */
    public function unit(): array
    {
        foreach (HeadquarterType::cases() as $type) {
            foreach (UnitLevel::cases() as $level) {
                $id = $this->getAttribute($level->column($type));

                if ($id !== null) {
                    return ['type' => $type, 'level' => $level, 'id' => (int) $id];
                }
            }
        }

        throw new LogicException('An API key has to belong to a unit.');
    }

    /** The unit as one string, e.g. "shops:branches:5" (the same form as active_unit). */
    public function unitRef(): string
    {
        ['type' => $type, 'level' => $level, 'id' => $id] = $this->unit();

        return $type->value.':'.$level->value.':'.$id;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<ShopHeadquarter, $this>
     */
    public function shopHeadquarter(): BelongsTo
    {
        return $this->belongsTo(ShopHeadquarter::class);
    }

    /**
     * @return BelongsTo<WarehouseHeadquarter, $this>
     */
    public function warehouseHeadquarter(): BelongsTo
    {
        return $this->belongsTo(WarehouseHeadquarter::class);
    }

    /**
     * @return BelongsTo<ShopBranch, $this>
     */
    public function shopBranch(): BelongsTo
    {
        return $this->belongsTo(ShopBranch::class);
    }

    /**
     * @return BelongsTo<WarehouseBranch, $this>
     */
    public function warehouseBranch(): BelongsTo
    {
        return $this->belongsTo(WarehouseBranch::class);
    }
}
