<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who of the people a task is for has already done their part. A task for a branch, a headquarters or the whole
     * organization is carried out by everyone below it; each of them marks their own part, and their superiors and the
     * owner see who has finished.
     */
    public function up(): void
    {
        Schema::create('task_completions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('completed_at')->useCurrent();

            // Which occurrence was done: "once" for an ordinary task, the date (Y-m-d) of the day for a standing task, where
            // every person marks every day separately. (A key, not a nullable date: a unique index over NULL is not unique.)
            $table->string('occurrence_key', 10)->default('once');

            $table->timestamps();

            $table->unique(['task_id', 'user_id', 'occurrence_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('task_completions');
    }
};
