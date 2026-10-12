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
        Schema::create('organization_invitations', function (Blueprint $table) {
            $table->id();

            // Organization the invited person will own. NULL for an invitation to create a new
            // organization: the invited person fills in its data after accepting the invitation.
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();

            // Random token used in the link sent by e-mail
            $table->string('code', 64)->unique();

            // Hashed 6-digit verification code sent in the same e-mail
            $table->string('verification_code');

            // Number of wrong verification code attempts (the invitation locks after too many)
            $table->unsignedTinyInteger('attempts')->default(0);

            // Address the invitation was sent to; only a user with this e-mail can accept it
            $table->string('email');

            // Role granted after acceptance (UserRole value)
            $table->string('role');

            $table->foreignId('invited_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();

            // Whether the invitation can still be used. Set to false when it is accepted or withdrawn.
            $table->boolean('is_active')->default(true);

            // When and by whom the invitation was withdrawn (cancelled by an administrator or replaced by a new one)
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['organization_id', 'email']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('organization_invitations');
    }
};
