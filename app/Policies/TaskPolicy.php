<?php

namespace App\Policies;

use App\Enums\HeadquarterType;
use App\Enums\UserRole;
use App\Models\Task;
use App\Models\User;

/**
 * Who may add, change and delete tasks. Seeing a task is decided by the query (TaskAccessScope), and seeing it does
 * not give the right to change it. The administrator only looks.
 */
class TaskPolicy
{
    /** The owner of the organization, a headquarters manager and a branch leader (each only with their unit). */
    public function create(User $user): bool
    {
        if ($user->organization_id === null) {
            return false;
        }

        if ($user->role === UserRole::ORGANIZATION_OWNER) {
            return true;
        }

        foreach (HeadquarterType::cases() as $type) {
            if ($user->role === $type->ownerRole()) {
                return $user->{$type->headquarterColumn()} !== null;
            }

            if ($user->role === $type->leaderRole()) {
                return $user->{$type->branchColumn()} !== null;
            }
        }

        return false;
    }

    /** The owner of the organization changes every task of it, the others only their own. */
    public function update(User $user, Task $task): bool
    {
        if ($user->organization_id === null || $task->organization_id !== $user->organization_id) {
            return false;
        }

        if ($user->role === UserRole::ORGANIZATION_OWNER) {
            return true;
        }

        return $this->create($user) && $task->created_by === $user->id;
    }

    public function delete(User $user, Task $task): bool
    {
        return $this->update($user, $task);
    }
}
