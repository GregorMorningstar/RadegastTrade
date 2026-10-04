<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Nie używamy WithoutModelEvents, bo barcode użytkownika generuje zdarzenie "created".
     */
    public function run(): void
    {
        $this->call(RoleUsersSeeder::class);
    }
}
