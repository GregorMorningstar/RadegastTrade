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
        // Test accounts with a known password only on a development machine: a whitelist (a staging or a test server that
        // is reachable from the network is not "production" by name, and must not get accounts with a published password).
        if (! app()->environment(['local', 'testing'])) {
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
