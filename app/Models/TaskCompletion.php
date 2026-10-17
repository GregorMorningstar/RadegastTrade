<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A person who has done their part of a task.
 *
 * @property int $id
 * @property int $task_id
 * @property int $user_id
 */
#[Fillable(['task_id', 'user_id', 'completed_at'])]
class TaskCompletion extends Model
{
    protected function casts(): array
    {
        return ['completed_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
