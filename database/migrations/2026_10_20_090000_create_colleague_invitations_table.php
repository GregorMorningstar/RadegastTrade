<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Invitation of a person of the same organization to the units of a cooperation (the right to place orders for them).
     * Sent by the owner or a headquarters manager and answered by the invited person with a code from their e-mail; when
     * the invited person is a branch leader, somebody who manages the leader (the headquarters manager, otherwise the
     * owner) has to consent first, also with a code.
     */
    public function up(): void
    {
        Schema::create('colleague_invitations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invited_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();

            // units ("shops:branches:5") the person gets the right to order for
            $table->json('unit_refs');

            // pending, accepted, rejected, cancelled
            $table->string('status', 16)->default('pending');

            $table->boolean('consent_required')->default(false);
            $table->timestamp('consent_requested_at')->nullable();
            $table->foreignId('consented_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('consented_at')->nullable();

            $table->timestamp('expires_at');
            $table->timestamp('responded_at')->nullable();

            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['invited_user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('colleague_invitations');
    }
};
