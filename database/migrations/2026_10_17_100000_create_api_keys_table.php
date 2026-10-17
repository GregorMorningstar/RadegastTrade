<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * API keys of connectors. A key is the key of one connector (the program that sends the data of a unit, e.g. the
     * ApiConnector on the computers of a shop headquarters or branch) and so it belongs to a unit, not to a person: one of
     * the four unit columns is set. A unit has at most one active key (active_unit is unique and empty once the key is
     * revoked).
     *
     * The key is random and is sent by e-mail to the person who generates it. The database keeps its keyed hash (what the
     * connector is recognised by), a short prefix, and an encrypted copy (with the application key) so a person who manages
     * the unit can show it again after confirming with the password; the copy is cleared when the key is removed.
     */
    public function up(): void
    {
        Schema::create('api_keys', function (Blueprint $table) {
            $table->id();

            $table->foreignId('organization_id')->constrained()->restrictOnDelete();

            // the unit of the connector: exactly one of these (checked by the application)
            $table->foreignId('shop_headquarter_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_headquarter_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('shop_branch_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_branch_id')->nullable()->constrained()->cascadeOnDelete();

            // "shops:branches:5" while the key is active, null once revoked: a unit cannot have two active keys
            $table->string('active_unit', 40)->nullable()->unique();

            // the connector's name (e.g. "Kasa 1") and who generated the key
            $table->string('name', 100);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            // the keyed hash of the key, a short prefix to recognise it, and the key encrypted (null once removed)
            $table->string('prefix', 16);
            $table->string('key_hash', 64)->unique();
            $table->text('key_encrypted')->nullable();

            // use of the key by the connector, and every showing of the key to a person (when, by whom)
            $table->timestamp('last_used_at')->nullable();
            $table->string('last_used_ip', 45)->nullable();
            $table->timestamp('revealed_at')->nullable();
            $table->foreignId('revealed_by')->nullable()->constrained('users')->nullOnDelete();

            // when the key was removed and by whom (empty while the key is active; the row stays as a record)
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();

            // removing the key is confirmed with a code sent to the person who asked for it: only its hash is stored, it
            // expires, and a few wrong attempts cancel it
            $table->string('removal_code_hash', 64)->nullable();
            $table->timestamp('removal_code_expires_at')->nullable();
            $table->unsignedTinyInteger('removal_attempts')->default(0);
            $table->foreignId('removal_requested_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('api_keys');
    }
};
