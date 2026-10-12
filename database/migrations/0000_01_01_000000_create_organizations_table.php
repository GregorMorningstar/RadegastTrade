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
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();

            // Name of the organization
            $table->string('name');

            $table->string('barcode', 13)->unique()->nullable();
          // Headquarters email address
          $table->string('NIP', 10)->unique()->nullable();
          $table->string('REGON', 9)->unique()->nullable();
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
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
