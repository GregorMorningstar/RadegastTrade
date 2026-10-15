<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who manages and who works in a unit: one row per person.
     *
     * A unit is a shop / warehouse headquarters or a shop / warehouse branch: exactly one of the four unit columns
     * is set (checked by the application). "unique(user_id)" means a person works in at most one unit.
     * The table is kept in step with the unit columns of users (see UserObserver), which the rest of the
     * application still reads.
     */
    public function up(): void
    {
        Schema::create('unit_memberships', function (Blueprint $table) {
            $table->id();

            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            // The unit: exactly one of these four
            $table->foreignId('shop_headquarter_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_headquarter_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('shop_branch_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_branch_id')->nullable()->constrained()->cascadeOnDelete();

            // manager (kierownik) or worker (pracownik); a headquarters has managers only
            $table->enum('position', ['manager', 'worker']);

            $table->timestamps();

            $table->index(['shop_headquarter_id', 'position']);
            $table->index(['warehouse_headquarter_id', 'position']);
            $table->index(['shop_branch_id', 'position']);
            $table->index(['warehouse_branch_id', 'position']);
        });

        $this->backfill();
    }

    public function down(): void
    {
        Schema::dropIfExists('unit_memberships');
    }

    /**
     * Fills the table from the accounts that already have a unit: headquarters managers, branch leaders
     * (managers) and branch workers.
     */
    private function backfill(): void
    {
        $now = now();

        foreach (['shop', 'warehouse'] as $group) {
            $rows = [
                ["{$group}_owner", "{$group}_headquarter_id", 'manager'],
                ["{$group}_leader", "{$group}_branch_id", 'manager'],
                ["{$group}_worker", "{$group}_branch_id", 'worker'],
            ];

            foreach ($rows as [$role, $unitColumn, $position]) {
                DB::table('users')
                    ->where('role', $role)
                    ->whereNotNull('organization_id')
                    ->whereNotNull($unitColumn)
                    ->orderBy('id')
                    ->each(fn ($user) => DB::table('unit_memberships')->insert([
                        'organization_id' => $user->organization_id,
                        'user_id' => $user->id,
                        $unitColumn => $user->{$unitColumn},
                        'position' => $position,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]));
            }
        }
    }
};
