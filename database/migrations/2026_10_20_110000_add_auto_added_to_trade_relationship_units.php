<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A unit that was invited separately and joined a cooperation because the owner or the manager of its headquarters
     * accepted the invitation: who accepted, and when the manager of the unit closed the alert about it.
     */
    public function up(): void
    {
        Schema::table('trade_relationship_units', function (Blueprint $table) {
            $table->foreignId('auto_added_by')->nullable()->after('granted_by')->constrained('users')->nullOnDelete();
            $table->timestamp('alert_dismissed_at')->nullable()->after('auto_added_by');
        });
    }

    public function down(): void
    {
        Schema::table('trade_relationship_units', function (Blueprint $table) {
            $table->dropConstrainedForeignId('auto_added_by');
            $table->dropColumn('alert_dismissed_at');
        });
    }
};
