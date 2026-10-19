<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Orders placed from offers, who may place them for a unit, and the audit log of the trade module.
     *
     * An order is a record of the past: the units, the people and every position are copied (names, prices, tax), so
     * a later change of the offer, the product or the structure does not change it. The units are kept as a type,
     * a level and an id without foreign keys for the same reason.
     */
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->unique();

            $table->foreignId('buyer_organization_id')->constrained('organizations')->restrictOnDelete();
            $table->string('buyer_unit_type', 12);
            $table->string('buyer_unit_level', 12);
            $table->unsignedBigInteger('buyer_unit_id');
            $table->string('buyer_unit_ref', 40);
            $table->string('buyer_unit_name');

            $table->foreignId('seller_organization_id')->constrained('organizations')->restrictOnDelete();
            $table->string('seller_unit_type', 12);
            $table->string('seller_unit_level', 12);
            $table->unsignedBigInteger('seller_unit_id');
            $table->string('seller_unit_ref', 40);
            $table->string('seller_unit_name');

            $table->foreignId('offer_id')->constrained()->restrictOnDelete();
            $table->foreignId('offer_version_id')->constrained()->restrictOnDelete();
            // null for an offer shared inside the organization
            $table->foreignId('relationship_id')->nullable()->constrained('trade_relationships')->restrictOnDelete();

            $table->foreignId('placed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('placed_by_name');

            // draft, submitted, confirmed, rejected, cancelled, fulfilled, received
            $table->string('status', 16)->default('submitted');

            $table->char('currency', 3);
            $table->decimal('subtotal', 14, 2);
            $table->decimal('tax_total', 14, 2);
            $table->decimal('total', 14, 2);

            $table->json('delivery_address');
            $table->text('notes')->nullable();

            // sent with the form: the same key from the same unit gives the same order, not a second one
            $table->string('idempotency_key', 64);

            $table->timestamp('placed_at');
            $table->timestamp('status_changed_at');

            $table->timestamps();

            $table->unique(['buyer_unit_ref', 'idempotency_key']);
            $table->index(['buyer_organization_id', 'status']);
            $table->index(['seller_organization_id', 'status']);
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('offer_item_id')->nullable()->constrained()->nullOnDelete();

            $table->string('sku', 64)->nullable();
            $table->string('name');
            $table->string('unit_of_measure', 16);
            $table->decimal('qty', 12, 3);
            $table->decimal('unit_price', 14, 4);
            $table->decimal('vat_rate', 5, 2)->nullable();
            $table->decimal('line_net', 14, 2);
            $table->decimal('line_tax', 14, 2);
            $table->decimal('line_gross', 14, 2);

            $table->timestamps();
        });

        Schema::create('order_status_history', function (Blueprint $table) {
            $table->id();

            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 16)->nullable();
            $table->string('to_status', 16);
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->timestamp('created_at');
        });

        // Who may place orders (or do other things in the future) for a unit, besides those who manage it
        Schema::create('unit_order_permissions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->foreignId('shop_headquarter_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_headquarter_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('shop_branch_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_branch_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('unit_ref', 40);

            $table->string('ability', 24)->default('place_orders');

            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('granted_at');
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();

            // "user:unit_ref:ability" while the permission is active, null once revoked
            $table->string('active_key', 80)->nullable()->unique();

            $table->timestamps();

            $table->index(['organization_id', 'unit_ref']);
        });

        // Append-only record of the key actions (who, what, when, from where)
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 64);
            $table->string('subject_type', 64)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('context')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at');

            $table->index(['organization_id', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('unit_order_permissions');
        Schema::dropIfExists('order_status_history');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
