<?php

namespace App\Models;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Concerns\GeneratesBarcode;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A task of an organization with its schedule, priority, progress and the people it is for.
 *
 * @property int $id
 * @property int $organization_id
 * @property int|null $created_by
 * @property TaskPriority $priority
 * @property TaskStatus $status
 */
#[Fillable([
    'title',
    'description',
    'priority',
    'status',
    'progress',
    'completed',
    'is_milestone',
    'all_day',
    'starts_at',
    'ends_at',
    'depends_on_id',
    'recurrence_from',
    'recurrence_to',
    'recurrence_days',
    'daily_start_time',
    'daily_end_time',
    'requires_completion_code',
])]
class Task extends Model
{
    use GeneratesBarcode;

    protected function barcodeKey(): string
    {
        return 'task';
    }

    protected function casts(): array
    {
        return [
            'priority' => TaskPriority::class,
            'status' => TaskStatus::class,
            'progress' => 'integer',
            'completed' => 'boolean',
            'is_milestone' => 'boolean',
            'all_day' => 'boolean',
            'requires_completion_code' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'recurrence_from' => 'date',
            'recurrence_to' => 'date',
            'recurrence_days' => 'array',
        ];
    }

    protected static function booted(): void
    {
        // "completed" mirrors the status, and a finished task is 100 % done
        static::saving(function (Task $task) {
            $task->completed = $task->status === TaskStatus::Done;

            if ($task->completed) {
                $task->progress = 100;
            }
        });
    }

    /**
     * Whether this is a standing task: it repeats on the chosen weekdays between two dates.
     */
    public function isRecurring(): bool
    {
        return $this->recurrence_from !== null;
    }

    /**
     * Whether the series has an occurrence on the given day (taken in the application's time zone): the day is inside the
     * period and its weekday (ISO, 1 = Monday) is one of the chosen ones.
     */
    public function occursOn(CarbonInterface $day): bool
    {
        if (! $this->isRecurring()) {
            return false;
        }

        $date = $day->setTimezone(config('app.timezone'))->toDateString();

        return $date >= $this->recurrence_from->toDateString()
            && $date <= $this->recurrence_to->toDateString()
            && in_array($day->setTimezone(config('app.timezone'))->dayOfWeekIso, array_map('intval', $this->recurrence_days ?? []), true);
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The task that has to be finished first.
     *
     * @return BelongsTo<Task, $this>
     */
    public function dependsOn(): BelongsTo
    {
        return $this->belongsTo(self::class, 'depends_on_id');
    }

    /**
     * @return HasMany<TaskAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(TaskAssignment::class);
    }

    /**
     * The people who have done their part.
     *
     * @return HasMany<TaskCompletion, $this>
     */
    public function completions(): HasMany
    {
        return $this->hasMany(TaskCompletion::class);
    }
}
