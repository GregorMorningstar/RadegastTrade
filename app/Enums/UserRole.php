<?php

namespace App\Enums;

/**
 * The roles of the platform and who invites whom:
 *
 *   Admin → organization owners → headquarters managers → branch leaders → workers
 *
 * The role of an account says what the account may do; where it works (headquarters / branch) and in which
 * position (manager / worker) is kept in the unit_memberships table.
 */
enum UserRole: string
{
    // Platform administrator: sends the invitations that create organizations (to their owners)
    case Admin    = 'admin';
    // Platform manager and employee: staff accounts without a panel (they only preview the structure of the
    // organizations); the employee is also the default role of an account until an invitation gives it another one
    case Manager  = 'manager';
    case Employee = 'employee';

    // Organization owner: sends invitations to the main headquarters (shops and warehouses)
    case ORGANIZATION_OWNER = 'organization_owner';

    // Warehouse: the main headquarters manager adds branches, the branch leader (manager) invites workers
    case WAREHOUSE_OWNER   = 'warehouse_owner';
    case WAREHOUSE_LEADER  = 'warehouse_leader';
    case WAREHOUSE_WORKER  = 'warehouse_worker';

    // Shop: the main headquarters manager adds branches, the branch leader (manager) invites workers
    case SHOP_OWNER        = 'shop_owner';
    case SHOP_LEADER       = 'shop_leader';
    case SHOP_WORKER       = 'shop_worker';

    /**
     * Role group used in the controller and URL structure: platform, warehouse or shop.
     */
    public function group(): string
    {
        return match (true) {
            $this->isWarehouse() => 'warehouse',
            $this->isShop() => 'shop',
            default => 'platform',
        };
    }

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
     * Whether the role has a panel: the roles of the invitation chain have one (the administrator, the organization
     * owner, the headquarters managers, the branch leaders and the workers); the manager and the employee have none.
     */
    public function hasPanel(): bool
    {
        return ! in_array($this, [self::Manager, self::Employee], true);
    }

    /**
     * Route name of the role's dedicated dashboard, e.g. shop.owner.dashboard. A role without a panel lands on
     * the page that waits for an invitation.
     */
    public function dashboardRoute(): string
    {
        return $this->hasPanel() ? $this->group().'.'.$this->slug().'.dashboard' : 'account.pending';
    }

    /**
     * What kind of unit the person works in, for the "Jednostka" label of the menu: the organization owner, a shop or
     * a warehouse; null for platform accounts.
     */
    public function unitLabel(): ?string
    {
        return match (true) {
            $this === self::ORGANIZATION_OWNER => 'Właściciel',
            $this->isShop() => 'Sklep',
            $this->isWarehouse() => 'Hurtownia',
            default => null,
        };
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
     *
     * @return list<string>
     */
    public static function getValues(): array
    {
        return array_map(
            fn (self $case) => $case->value,
            self::cases()
        );
    }
}
