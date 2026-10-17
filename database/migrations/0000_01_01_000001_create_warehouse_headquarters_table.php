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
        Schema::create('warehouse_headquarters', function (Blueprint $table) {
            // Primary identifier of the headquarters
            $table->id();

            // Organization that owns this headquarters.
            // One organization can have many headquarters, but a headquarters
            // belongs to exactly one organization.
            $table->foreignId('organization_id')
                ->constrained()
                ->restrictOnDelete();

            // Name of the warehouse headquarters (unique within the organization)
            $table->string('name');

            // Unique EAN-13 barcode assigned to the headquarters
            $table->string('barcode', 13)->nullable()->unique();

            // Tax ID (NIP). NOT unique: a headquarters may share the NIP of its organization.
            // Two different organizations cannot share a NIP (it is unique in organizations).
            $table->string('NIP', 10)->nullable()->index();

            // Headquarters email address
            $table->string('email')->nullable();

            // Headquarters phone number
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

            // Determines whether the headquarters is active
            $table->boolean('is_active')->default(true);

            // Record creation and last update timestamps
            $table->timestamps();

            // The same name cannot be used twice within one organization
            $table->unique(['organization_id', 'name']);

            // the target of the composite key of the branches (a branch is in the same organization as its headquarters)
            $table->unique(['organization_id', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('warehouse_headquarters');
    }
};
