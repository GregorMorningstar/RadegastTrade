<?php

namespace App\Support;

use App\Enums\HeadquarterType;
use App\Enums\UnitLevel;
use App\Models\ShopBranch;
use App\Models\ShopHeadquarter;
use App\Models\WarehouseBranch;
use App\Models\WarehouseHeadquarter;
use Illuminate\Database\Eloquent\Model;

/**
 * One unit of the structure (a shop / warehouse headquarters or branch) as a value: type, level and id.
 * Its string form ("shops:branches:5") is the one the api_keys table already uses. A reference that comes from the
 * request is only a claim: check it with find() against the organization before trusting it.
 */
final class UnitRef
{
    public function __construct(
        public readonly HeadquarterType $type,
        public readonly UnitLevel $level,
        public readonly int $id,
    ) {
        //
    }

    public function ref(): string
    {
        return $this->type->value.':'.$this->level->value.':'.$this->id;
    }

    public static function parse(mixed $value): ?self
    {
        if (! is_string($value) || ! preg_match('/^(shops|warehouses):(headquarters|branches):([1-9][0-9]{0,18})$/', $value, $m)) {
            return null;
        }

        return new self(HeadquarterType::from($m[1]), UnitLevel::from($m[2]), (int) $m[3]);
    }

    public static function of(Model $unit): ?self
    {
        return match (true) {
            $unit instanceof ShopHeadquarter => new self(HeadquarterType::Shop, UnitLevel::Headquarters, $unit->id),
            $unit instanceof WarehouseHeadquarter => new self(HeadquarterType::Warehouse, UnitLevel::Headquarters, $unit->id),
            $unit instanceof ShopBranch => new self(HeadquarterType::Shop, UnitLevel::Branch, $unit->id),
            $unit instanceof WarehouseBranch => new self(HeadquarterType::Warehouse, UnitLevel::Branch, $unit->id),
            default => null,
        };
    }

    /**
     * The unit stored in the four unit columns of a row (null when none is set).
     *
     * @param  Model|array<string, mixed>  $row
     */
    public static function fromRow(Model|array $row): ?self
    {
        $get = fn (string $column) => $row instanceof Model ? $row->getAttribute($column) : ($row[$column] ?? null);

        foreach (HeadquarterType::cases() as $type) {
            foreach (UnitLevel::cases() as $level) {
                $id = $get($level->column($type));

                if ($id !== null) {
                    return new self($type, $level, (int) $id);
                }
            }
        }

        return null;
    }

    /**
     * The four unit columns with only this unit's one set (for create()).
     *
     * @return array<string, int|null>
     */
    public function columns(): array
    {
        $columns = [];

        foreach (HeadquarterType::cases() as $type) {
            $columns[$type->headquarterColumn()] = null;
            $columns[$type->branchColumn()] = null;
        }

        $columns[$this->level->column($this->type)] = $this->id;

        return $columns;
    }

    /** @return class-string<Model> */
    public function modelClass(): string
    {
        return match (true) {
            $this->type === HeadquarterType::Shop && $this->level === UnitLevel::Headquarters => ShopHeadquarter::class,
            $this->type === HeadquarterType::Warehouse && $this->level === UnitLevel::Headquarters => WarehouseHeadquarter::class,
            $this->type === HeadquarterType::Shop => ShopBranch::class,
            default => WarehouseBranch::class,
        };
    }

    /** The unit of the given organization, or null when it does not exist or belongs to another organization. */
    public function find(int $organizationId): ?Model
    {
        return $this->modelClass()::query()->where('organization_id', $organizationId)->find($this->id);
    }

    public function isWarehouse(): bool
    {
        return $this->type === HeadquarterType::Warehouse;
    }

    public function isBranch(): bool
    {
        return $this->level === UnitLevel::Branch;
    }

    public function equals(self $other): bool
    {
        return $this->ref() === $other->ref();
    }
}
