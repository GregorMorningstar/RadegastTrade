<?php

use Database\Seeders\RoleUsersSeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Konta testowe ze znanym hasłem tylko na środowiskach deweloperskich.
        if (app()->environment('production')) {
            return;
        }

        (new RoleUsersSeeder)->run();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Celowo puste: nie usuwamy kont, bo mogły zostać zmienione po utworzeniu.
    }
};
