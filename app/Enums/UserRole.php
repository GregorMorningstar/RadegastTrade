<?php

namespace App\Enums;

enum UserRole: string
{
    // Universal roles (e.g. for the platform / general access)
    case Admin    = 'admin';
    case Manager  = 'manager';
    case Employee = 'employee';

    // Organization owner (the parent unit: invites warehouse and shop headquarters)
    case ORGANIZATION_OWNER = 'organization_owner';

    // Warehouse
    case WAREHOUSE_OWNER   = 'warehouse_owner';
    case WAREHOUSE_LEADER  = 'warehouse_leader';
    case WAREHOUSE_WORKER  = 'warehouse_worker';

    // Shop
    case SHOP_OWNER        = 'shop_owner';
    case SHOP_LEADER       = 'shop_leader';
    case SHOP_WORKER       = 'shop_worker';

    /**
     * Role group used in the folder, controller and URL structure: platform, warehouse or shop.
     */
    /**
     * Role views folder in resources/js/pages (everything lives under platform/).
     */
    public function pageGroup(): string
    {
        return match (true) {
            $this->isWarehouse() => 'platform/warehouses',
            $this->isShop() => 'platform/shop',
            default => 'platform',
        };
    }

    public function group(): string
    {
        return match (true) {
            $this->isWarehouse() => 'warehouse',
            $this->isShop() => 'shop',
            default => 'platform',
        };
    }

    /**
     * Role name without the group prefix, e.g. owner, leader, worker, manager.
     */
    public function slug(): string
    {
        // the role value in the database is organization_owner, but URLs and routes use just "organization"
        if ($this === self::ORGANIZATION_OWNER) {
            return 'organization';
        }

        return $this->isWarehouse() || $this->isShop()
            ? substr($this->value, strpos($this->value, '_') + 1)
            : $this->value;
    }

    /**
     * Base URL of the role panel, e.g. /shop/owner.
     */
    public function basePath(): string
    {
        return '/'.$this->group().'/'.$this->slug();
    }

    /**
     * Short panel name shown in the side menu.
     */
    public function panelName(): string
    {
        return match ($this) {
            self::Admin => 'Administrator',
            self::Manager => 'Manager',
            self::Employee => 'Pracownik',
            self::ORGANIZATION_OWNER => 'Właściciel organizacji',
            self::WAREHOUSE_OWNER => 'Hurtownia - kierownik',
            self::WAREHOUSE_LEADER => 'Hurtownia - leader',
            self::WAREHOUSE_WORKER => 'Hurtownia - pracownik',
            self::SHOP_OWNER => 'Sklep - kierownik',
            self::SHOP_LEADER => 'Sklep - leader',
            self::SHOP_WORKER => 'Sklep - pracownik',
        };
    }

    /**
     * Route name of the role's dedicated dashboard, e.g. shop.owner.dashboard.
     */
    public function dashboardRoute(): string
    {
        return $this->group().'.'.$this->slug().'.dashboard';
    }

    /**
     * Whether the role belongs to a warehouse.
     */
    public function isWarehouse(): bool
    {
        return in_array($this, [
            self::WAREHOUSE_OWNER,
            self::WAREHOUSE_LEADER,
            self::WAREHOUSE_WORKER,
        ], true);
    }

    /**
     * Whether the role belongs to a shop.
     */
    public function isShop(): bool
    {
        return in_array($this, [
            self::SHOP_OWNER,
            self::SHOP_LEADER,
            self::SHOP_WORKER,
        ], true);
    }

    /**
     * Whether the role can send invitations within a team (for children / new users).
     */
    public function canSendInvites(): bool
    {
        return in_array($this, [
            self::WAREHOUSE_OWNER,
            self::WAREHOUSE_LEADER,
            self::SHOP_OWNER,
            self::SHOP_LEADER,
        ], true);
    }

    /**
     * Whether the role can manage team members (change roles, remove from the team).
     */
    public function canManageTeam(): bool
    {
        return in_array($this, [
            self::WAREHOUSE_OWNER,
            self::SHOP_OWNER,
        ], true);
    }

    /**
     * Human-friendly role name.
     */
    public function label(): string
    {
        return match ($this) {
            self::Admin               => 'Administrator',
            self::Manager             => 'Manager',
            self::Employee            => 'Pracownik',

            self::ORGANIZATION_OWNER  => 'Właściciel organizacji',

            self::WAREHOUSE_OWNER     => 'Główny kierownik (hurtownia)',
            self::WAREHOUSE_LEADER    => 'Leader (hurtownia)',
            self::WAREHOUSE_WORKER    => 'Pracownik (hurtownia)',

            self::SHOP_OWNER          => 'Główny kierownik (sklep)',
            self::SHOP_LEADER         => 'Leader (sklep)',
            self::SHOP_WORKER         => 'Pracownik (sklep)',
        };
    }

    /**
     * List of all values as a string array (useful in migrations / validation).
     */
    public static function getValues(): array
    {
        return array_map(
            fn (self $case) => $case->value,
            self::cases()
        );
    }
}