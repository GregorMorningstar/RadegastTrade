<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Trade cooperation between two organizations. A relationship is directional (supplier → buyer): the opposite
     * direction is a separate relationship with its own invitation. Its scope is a list of units per side; a unit that
     * is not on the list has no access, and a unit created later is not added automatically.
     */
    public function up(): void
    {
        Schema::create('trade_relationships', function (Blueprint $table) {
            $table->id();

            $table->foreignId('supplier_organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignId('buyer_organization_id')->constrained('organizations')->restrictOnDelete();

            // active, suspended, ended
            $table->string('status', 16)->default('active');
            $table->unsignedBigInteger('invitation_id')->nullable()->index();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->foreignId('suspended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('ended_at')->nullable();
            $table->foreignId('ended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('status_reason')->nullable();

            $table->timestamps();

            // a short name: the generated one is longer than MySQL allows for an index
            $table->unique(['supplier_organization_id', 'buyer_organization_id'], 'trade_relationships_pair_unique');
        });

        Schema::create('trade_relationship_units', function (Blueprint $table) {
            $table->id();

            $table->foreignId('relationship_id')->constrained('trade_relationships')->cascadeOnDelete();
            // supplier or buyer: which side of the relationship the unit is on
            $table->string('side', 8);
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();

            // the unit: exactly one of these four (checked by the application) and the same as one string
            $table->foreignId('shop_headquarter_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_headquarter_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('shop_branch_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_branch_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('unit_ref', 40);

            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();

            // "relationship:side:unit_ref" while the unit is in the scope, null once revoked (one active row per unit)
            $table->string('active_key', 80)->nullable()->unique();

            $table->timestamps();

            $table->index(['relationship_id', 'side']);
            $table->index(['organization_id', 'unit_ref']);
        });

        Schema::create('trade_invitations', function (Blueprint $table) {
            $table->id();

            // the organization that invites; inviter_role says whether it offers (supplier) or buys (buyer)
            $table->foreignId('inviter_organization_id')->constrained('organizations')->restrictOnDelete();
            $table->string('inviter_role', 8);

            // only an organization that exists in the system; resolved by the server, never taken from the form
            $table->foreignId('invitee_organization_id')->constrained('organizations')->restrictOnDelete();
            $table->string('invitee_email');

            // units: proposed scope of the invitee ("units": the listed ones, "open": the accepting person chooses)
            $table->string('invitee_scope', 8)->default('open');
            $table->text('message')->nullable();

            // only the hash of the one-time token is stored; the token itself is only in the e-mailed link
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at');

            // pending, accepted, rejected, cancelled, expired
            $table->string('status', 16)->default('pending');

            $table->foreignId('invited_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('responded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('responded_at')->nullable();
            $table->foreignId('relationship_id')->nullable()->constrained('trade_relationships')->nullOnDelete();

            $table->timestamps();

            $table->index(['inviter_organization_id', 'status']);
            $table->index(['invitee_organization_id', 'status']);
        });

        Schema::create('trade_invitation_units', function (Blueprint $table) {
            $table->id();

            $table->foreignId('invitation_id')->constrained('trade_invitations')->cascadeOnDelete();
            // inviter or invitee
            $table->string('side', 8);

            $table->foreignId('shop_headquarter_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_headquarter_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('shop_branch_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_branch_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('unit_ref', 40);

            $table->timestamps();

            $table->index(['invitation_id', 'side']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trade_invitation_units');
        Schema::dropIfExists('trade_invitations');
        Schema::dropIfExists('trade_relationship_units');
        Schema::dropIfExists('trade_relationships');
    }
};
