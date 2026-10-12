<?php

namespace App\Enums;

/**
 * Type of an organization's headquarters. The value is a URL segment (/platform/organization/{type}).
 */
enum HeadquarterType: string
{
    case Shop = 'shops';
    case Warehouse = 'warehouses';

    /**
     * Role of the manager invited to a headquarters of this type.
     */
    public function ownerRole(): UserRole
    {
        return match ($this) {
            self::Shop => UserRole::SHOP_OWNER,
            self::Warehouse => UserRole::WAREHOUSE_OWNER,
        };
    }

    /**
     * Type of headquarters managed by a manager with the given role (null for other roles).
     */
    public static function forRole(UserRole $role): ?self
    {
        return match ($role) {
            UserRole::SHOP_OWNER => self::Shop,
            UserRole::WAREHOUSE_OWNER => self::Warehouse,
            default => null,
        };
    }

    /**
     * Role of the leader invited to a branch of this type.
     */
    public function leaderRole(): UserRole
    {
        return match ($this) {
            self::Shop => UserRole::SHOP_LEADER,
            self::Warehouse => UserRole::WAREHOUSE_LEADER,
        };
    }

    /**
     * Type of branch managed by a leader with the given role (null for other roles).
     */
    public static function forLeaderRole(UserRole $role): ?self
    {
        return match ($role) {
            UserRole::SHOP_LEADER => self::Shop,
            UserRole::WAREHOUSE_LEADER => self::Warehouse,
            default => null,
        };
    }

    /**
     * Role of the worker invited to a branch of this type.
     */
    public function workerRole(): UserRole
    {
        return match ($this) {
            self::Shop => UserRole::SHOP_WORKER,
            self::Warehouse => UserRole::WAREHOUSE_WORKER,
        };
    }

    /**
     * Type of branch where a worker with the given role works (null for other roles).
     */
    public static function forWorkerRole(UserRole $role): ?self
    {
        return match ($role) {
            UserRole::SHOP_WORKER => self::Shop,
            UserRole::WAREHOUSE_WORKER => self::Warehouse,
            default => null,
        };
    }

    /**
     * Role panel group: shop or warehouse (URLs /shop/owner, /warehouse/leader).
     */
    public function pageGroup(): string
    {
        return $this->ownerRole()->pageGroup();
    }

    public function group(): string
    {
        return $this->ownerRole()->group();
    }

    /**
     * Column with the headquarters id in the users, organization_invitations and branches tables.
     */
    public function headquarterColumn(): string
    {
        return match ($this) {
            self::Shop => 'shop_headquarter_id',
            self::Warehouse => 'warehouse_headquarter_id',
        };
    }

    /**
     * Column with the branch id in the users and organization_invitations tables.
     */
    public function branchColumn(): string
    {
        return match ($this) {
            self::Shop => 'shop_branch_id',
            self::Warehouse => 'warehouse_branch_id',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Shop => 'Sklepy',
            self::Warehouse => 'Hurtownie',
        };
    }

    /**
     * Inertia page component.
     */
    public function page(): string
    {
        return 'platform/organization/'.$this->value.'/index';
    }
}
