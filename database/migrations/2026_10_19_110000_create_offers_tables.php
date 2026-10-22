<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Trade offers. An offer belongs to an organization, to the (warehouse) unit that presents it and to its author. What
     * is offered lives in versions: a published version never changes, a change creates a new version. An offer is shown
     * to its own organization (shared_internally) and/or to units of other organizations through offer_shares.
     */
    public function up(): void
    {
        Schema::create('offers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('number', 32);

            // Unique EAN-13 barcode of the offer (assigned by the model after creation, see config/barcode.php)
            $table->string('barcode', 13)->nullable()->unique();

            // the unit that presents the offer: exactly one of these four, and the same as one string
            $table->foreignId('shop_headquarter_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_headquarter_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('shop_branch_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_branch_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('unit_ref', 40);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            // draft, published, withdrawn, archived ("expired" is worked out from the validity date of the version)
            $table->string('status', 16)->default('draft');
            $table->boolean('shared_internally')->default(false);

            // the published version people see (null while there is none)
            $table->unsignedBigInteger('current_version_id')->nullable();

            $table->timestamp('published_at')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->foreignId('withdrawn_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'status']);
            $table->index('unit_ref');
        });

        Schema::create('offer_versions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('offer_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version_no');

            // draft (editable), published (frozen), superseded (frozen, replaced by a newer one)
            $table->string('status', 16)->default('draft');

            $table->string('title');
            $table->text('description')->nullable();
            $table->char('currency', 3)->default('PLN');
            $table->dateTime('valid_from')->nullable();
            $table->dateTime('valid_to')->nullable();
            $table->text('delivery_terms')->nullable();

            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->char('content_hash', 64)->nullable();

            // the offer id while the version is a draft, null otherwise: an offer has at most one draft
            $table->unsignedBigInteger('draft_key')->nullable()->unique();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['offer_id', 'version_no']);
        });

        Schema::create('offer_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('offer_version_id')->constrained()->cascadeOnDelete();

            // where the position came from (no foreign key: the catalogue can change; the data below is a copy)
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('sku', 64)->nullable();
            $table->string('name');
            $table->string('barcode', 13)->nullable();
            $table->string('unit_of_measure', 16)->default('szt.');

            $table->decimal('unit_price', 14, 4);
            $table->decimal('vat_rate', 5, 2)->nullable();

            $table->decimal('min_qty', 12, 3)->default(1);
            $table->decimal('qty_step', 12, 3)->default(1);
            $table->decimal('max_qty', 12, 3)->nullable();

            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('offer_attachments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('offer_version_id')->constrained()->cascadeOnDelete();

            // a file on the private disk, served only through an authorized download
            $table->string('path');
            $table->string('original_name');
            $table->string('mime', 100);
            $table->unsignedInteger('size');
            $table->char('sha256', 64);

            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Who the offer was shared with: one row per unit of another organization
        Schema::create('offer_shares', function (Blueprint $table) {
            $table->id();

            $table->foreignId('offer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('relationship_id')->constrained('trade_relationships')->restrictOnDelete();

            $table->foreignId('recipient_organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignId('shop_headquarter_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_headquarter_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('shop_branch_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_branch_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('unit_ref', 40);

            // seeing the offer and ordering from it are separate rights
            $table->boolean('allow_order')->default(true);

            $table->foreignId('shared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('shared_at');
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();

            // "offer:unit_ref" while the share is active, null once revoked
            $table->string('active_key', 60)->nullable()->unique();

            $table->timestamps();

            $table->index(['recipient_organization_id', 'unit_ref']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offer_shares');
        Schema::dropIfExists('offer_attachments');
        Schema::dropIfExists('offer_items');
        Schema::dropIfExists('offer_versions');
        Schema::dropIfExists('offers');
    }
};
