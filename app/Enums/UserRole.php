<?php

namespace App\Enums;

enum UserRole: string
{
    // Uniwersalne role (np. dla platformy / ogólnego dostępu)
    case Admin    = 'admin';
    case Manager  = 'manager';
    case Employee = 'employee';

    // Hurtownia / magazyn
    case WAREHOUSE_OWNER   = 'warehouse_owner';
    case WAREHOUSE_LEADER  = 'warehouse_leader';
    case WAREHOUSE_WORKER  = 'warehouse_worker';

    // Sklep
    case SHOP_OWNER        = 'shop_owner';
    case SHOP_LEADER       = 'shop_leader';
    case SHOP_WORKER       = 'shop_worker';

    /**
     * Grupa roli używana w strukturze folderów, kontrolerów i adresów: platform, warehouse lub shop.
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
     * Nazwa roli bez przedrostka grupy, np. owner, leader, worker, manager.
     */
    public function slug(): string
    {
        return $this->isWarehouse() || $this->isShop()
            ? substr($this->value, strpos($this->value, '_') + 1)
            : $this->value;
    }

    /**
     * Adres bazowy panelu roli, np. /shop/owner.
     */
    public function basePath(): string
    {
        return '/'.$this->group().'/'.$this->slug();
    }

    /**
     * Krótka nazwa panelu wyświetlana w menu bocznym.
     */
    public function panelName(): string
    {
        return match ($this) {
            self::Admin => 'Administrator',
            self::Manager => 'Manager',
            self::Employee => 'Pracownik',
            self::WAREHOUSE_OWNER => 'Hurtownia - kierownik',
            self::WAREHOUSE_LEADER => 'Hurtownia - leader',
            self::WAREHOUSE_WORKER => 'Hurtownia - pracownik',
            self::SHOP_OWNER => 'Sklep - kierownik',
            self::SHOP_LEADER => 'Sklep - leader',
            self::SHOP_WORKER => 'Sklep - pracownik',
        };
    }

    /**
     * Nazwa trasy dedykowanego dashboardu roli, np. shop.owner.dashboard.
     */
    public function dashboardRoute(): string
    {
        return $this->group().'.'.$this->slug().'.dashboard';
    }

    /**
     * Czy rola należy do hurtowni.
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
     * Czy rola należy do sklepu.
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
     * Czy rola może wysyłać zaproszenia w ramach teamu (dla dzieci / nowych użytkowników).
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
     * Czy rola może zarządzać członkami teamu (zmiana ról, usuwanie z teamu).
     */
    public function canManageTeam(): bool
    {
        return in_array($this, [
            self::WAREHOUSE_OWNER,
            self::SHOP_OWNER,
        ], true);
    }

    /**
     * Przyjazna nazwa roli.
     */
    public function label(): string
    {
        return match ($this) {
            self::Admin               => 'Administrator',
            self::Manager             => 'Manager',
            self::Employee            => 'Pracownik',

            self::WAREHOUSE_OWNER     => 'Główny kierownik (hurtownia)',
            self::WAREHOUSE_LEADER    => 'Leader (hurtownia)',
            self::WAREHOUSE_WORKER    => 'Pracownik (hurtownia)',

            self::SHOP_OWNER          => 'Główny kierownik (sklep)',
            self::SHOP_LEADER         => 'Leader (sklep)',
            self::SHOP_WORKER         => 'Pracownik (sklep)',
        };
    }

    /**
     * Lista wszystkich wartości jako tablica stringów (przydatne w migracjach / walidacji).
     */
    public static function getValues(): array
    {
        return array_map(
            fn (self $case) => $case->value,
            self::cases()
        );
    }
}