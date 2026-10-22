<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tasks of an organization with their schedule (for the calendar and the Gantt chart) and who they are for.
     *
     * A task is created by the organization owner, a headquarters manager or a branch leader and is assigned (see
     * task_assignments) to the whole organization, to a headquarters (with its branches and people), to a branch or
     * to one or more people. A milestone is a task of one moment (starts_at = ends_at).
     */
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();

            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            // Unique EAN-13 barcode of the task (assigned by the model after creation, see config/barcode.php)
            $table->string('barcode', 13)->nullable()->unique();

            $table->string('title');
            $table->text('description')->nullable();

            // Importance and progress; "completed" mirrors status = done
            $table->enum('priority', ['low', 'normal', 'high', 'urgent'])->default('normal');
            $table->enum('status', ['todo', 'in_progress', 'done'])->default('todo');
            $table->unsignedTinyInteger('progress')->default(0);
            $table->boolean('completed')->default(false);

            // Schedule: a milestone has one moment (starts_at = ends_at); an all-day task spans whole days
            $table->boolean('is_milestone')->default(false);
            $table->boolean('all_day')->default(false);
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');

            // Whether the person who carries the task out has to confirm that they did it with a code from their e-mail
            // (a code is e-mailed to them when they mark it as done)
            $table->boolean('requires_completion_code')->default(false);

            // A standing (recurring) task: it repeats on the chosen weekdays (ISO: 1 = Monday ... 7 = Sunday) from one date to
            // another, e.g. every working day of a month, optionally within a daily time window (empty = the whole day).
            // starts_at / ends_at then hold the first and the last moment of the series, so the period filters, the Gantt chart
            // and the dependencies work as for any task. Empty recurrence_from = an ordinary task.
            $table->date('recurrence_from')->nullable();
            $table->date('recurrence_to')->nullable();
            $table->json('recurrence_days')->nullable();
            $table->time('daily_start_time')->nullable();
            $table->time('daily_end_time')->nullable();

            // The task that has to be finished first (the arrow on the Gantt chart)
            $table->foreignId('depends_on_id')->nullable()->constrained('tasks')->nullOnDelete();

            $table->timestamps();

            $table->index(['organization_id', 'starts_at']);
        });

        // Who a task is for: a row per target. A unit target covers everyone below it (hierarchy).
        Schema::create('task_assignments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('task_id')->constrained()->cascadeOnDelete();

            // organization (everyone), headquarters, branch (a unit) or user (one person)
            $table->enum('assignee_type', ['organization', 'headquarters', 'branch', 'user']);

            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('shop_headquarter_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_headquarter_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('shop_branch_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_branch_id')->nullable()->constrained()->cascadeOnDelete();

            $table->timestamps();

            $table->index('task_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('task_assignments');
        Schema::dropIfExists('tasks');
    }
};
