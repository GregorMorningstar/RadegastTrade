<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The unit of the other organization an invitation was addressed to ("shops:branches:5"; null when it was sent to
     * an e-mail address). Every chosen unit gets its own invitation.
     */
    public function up(): void
    {
        Schema::table('trade_invitations', function (Blueprint $table) {
            $table->string('invitee_unit_ref', 40)->nullable()->after('invitee_email');
        });
    }

    public function down(): void
    {
        Schema::table('trade_invitations', function (Blueprint $table) {
            $table->dropColumn('invitee_unit_ref');
        });
    }
};
