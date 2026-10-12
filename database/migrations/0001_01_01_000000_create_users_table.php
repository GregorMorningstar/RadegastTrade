<?php

use App\Enums\UserRole;
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
        Schema::create('users', function (Blueprint $table) {
            $table->id();
    $table->enum('role', UserRole::getValues());

            // Organization the user belongs to (the organizations table is created before users)
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();

            // Headquarters the user manages (their tables are created before users).
            // Set when the invitation is accepted or the headquarters is created.
            $table->foreignId('shop_headquarter_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('warehouse_headquarter_id')->nullable()->constrained()->nullOnDelete();

            // Branch the user manages (set when the branch is created after accepting the invitation)
            $table->foreignId('shop_branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('warehouse_branch_id')->nullable()->constrained()->nullOnDelete();

            $table->string('barcode', 13)->nullable()->unique(); // filled in by the model after creation (needs the ID)
            $table->string('name');
            $table->string('email')->unique();

            // Profile picture (path on the public disk) and last activity, used by the chat ("active" users)
            $table->string('avatar_path')->nullable();
            $table->timestamp('last_seen_at')->nullable();

            // Phone number in E.164 form (+48123456789); unique, filled from the invitation
            $table->string('phone', 20)->nullable()->unique();

            $table->timestamp('email_verified_at')->nullable();

            // Proof of consent given at registration (terms of service and GDPR information)
            $table->timestamp('terms_accepted_at')->nullable();
            $table->timestamp('privacy_accepted_at')->nullable();
            $table->string('password');

            // Last successful login: UTC moment plus the time zone of the place it happened,
            // so the local time at that location can be shown (e.g. Europe/Warsaw)
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_timezone', 64)->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
