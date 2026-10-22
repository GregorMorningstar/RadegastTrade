<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('warehouse_branches', function (Blueprint $table) {
            $table->id();

            // Organization the branch belongs to (taken from the invitation, never from a form)
            $table->foreignId('organization_id')
                ->constrained()
                ->restrictOnDelete();

            // Warehouse headquarters the branch belongs to. Empty for a warehouse founded straight under the organization; the
            // owner attaches it to a headquarters created later. (The unique name below is not enforced over an empty
            // headquarters: for those the application keeps the name unique within the organization.)
            $table->foreignId('warehouse_headquarter_id')
                ->nullable()
                ->constrained()
                ->restrictOnDelete();

            // Name of the branch (unique within the headquarters)
            $table->string('name');

            // Unique EAN-13 barcode assigned to the branch
            $table->string('barcode', 13)->nullable()->unique();

            // Tax ID (NIP). NOT unique: a branch may share the NIP of its organization or headquarters.
            $table->string('NIP', 10)->nullable()->index();

            // Branch email address
            $table->string('email')->nullable();

            // Branch phone number
            $table->string('phone', 20)->nullable();

            // Street name
            $table->string('street')->nullable();

            // Building number
            $table->string('building_number', 20)->nullable();

            // Apartment or office number
            $table->string('apartment_number', 20)->nullable();

            // Postal code
            $table->string('postal_code', 10)->nullable();

            // City name
            $table->string('city')->nullable();

            // Country code in ISO 3166-1 alpha-2 format, e.g. PL
            $table->string('country', 2)->default('PL');

            // Determines whether the branch is active
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // The same name cannot be used twice within one headquarters
            $table->unique(['warehouse_headquarter_id', 'name']);

            // the branch is in the same organization as its headquarters (checked by the database, not only the application)
            $table->foreign(['organization_id', 'warehouse_headquarter_id'], 'warehouse_branches_org_hq_foreign')
                ->references(['organization_id', 'id'])
                ->on('warehouse_headquarters')
                ->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('warehouse_branches');
    }
};
