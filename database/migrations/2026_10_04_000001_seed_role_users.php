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
        // Test accounts with a known password only in development environments.
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
        // Intentionally empty: accounts are not removed because they may have been changed after creation.
    }
};
