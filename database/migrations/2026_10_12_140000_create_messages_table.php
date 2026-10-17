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
        Schema::create('messages', function (Blueprint $table) {
            $table->id();

            // Chat is limited to people of one organization: both users must belong to it
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();

            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('recipient_id')->constrained('users')->cascadeOnDelete();

            $table->text('body');

            // When the recipient has seen the message
            $table->timestamp('read_at')->nullable();

            $table->timestamps();

            $table->index(['recipient_id', 'read_at']);
            $table->index(['sender_id', 'recipient_id', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
