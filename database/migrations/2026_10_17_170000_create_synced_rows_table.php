<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rows that the connector of a unit sends from its database (one table per request). A row belongs to the unit of the
     * API key it came with (never to a unit named in the data): the same source id of two units gives two rows, and one
     * unit's data can neither be overwritten nor read with the key of another.
     */
    public function up(): void
    {
        Schema::create('synced_rows', function (Blueprint $table) {
            $table->id();

            $table->foreignId('organization_id')->constrained()->restrictOnDelete();

            // the unit the data belongs to: one of these (the connector's unit), and the same as one string
            $table->foreignId('shop_headquarter_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_headquarter_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('shop_branch_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_branch_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('unit_ref', 40);

            // the table of the source database (dbo.Towary is dbo_Towary) and the id of the row in it
            $table->string('table_name', 128);
            $table->string('external_id', 191);
            $table->json('data');

            // when the connector read the data (its clock), for the freshness of the unit's data
            $table->timestamp('synced_at')->nullable();

            $table->timestamps();

            $table->unique(['unit_ref', 'table_name', 'external_id']);
            $table->index(['organization_id', 'unit_ref', 'table_name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('synced_rows');
    }
};
