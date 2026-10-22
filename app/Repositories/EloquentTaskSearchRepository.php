<?php

namespace App\Repositories;

use App\Models\Task;
use App\Models\TaskAssignment;
use App\Repositories\Concerns\ScopesTasks;
use App\Repositories\Contracts\TaskSearchRepositoryInterface;
use App\Support\TaskAccessScope;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Searching tasks. The visibility rules are the ones of every other read of tasks (ScopesTasks): the search only narrows
 * what the person already sees, it never widens it. The filters are a fixed list, and the text is escaped, so it can only
 * ever be matched literally.
 */
class EloquentTaskSearchRepository implements TaskSearchRepositoryInterface
{
    use ScopesTasks;

    public function search(?TaskAccessScope $scope, int $viewerId, array $criteria, int $page, int $perPage): LengthAwarePaginator
    {
        $query = Task::query()->with(self::ROW_RELATIONS);

        if ($scope !== null) {
            $query = $this->applyScope($query, $scope);
        }

        $text = trim((string) ($criteria['q'] ?? ''));

        return $query
            ->when($text !== '', fn (Builder $query) => $query->where(function (Builder $match) use ($text) {
                // "!" is the escape character, so a "%" or "_" typed by the person is an ordinary character
                $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $text).'%';

                $match->whereRaw("title LIKE ? ESCAPE '!'", [$like])
                    ->orWhereRaw("description LIKE ? ESCAPE '!'", [$like])
                    ->orWhere('barcode', $text);
            }))
            ->when(($criteria['status'] ?? null) !== null, fn (Builder $query) => $query->where('status', $criteria['status']))
            ->when(($criteria['priority'] ?? null) !== null, fn (Builder $query) => $query->where('priority', $criteria['priority']))
            ->when(($criteria['kind'] ?? null) === 'recurring', fn (Builder $query) => $query->whereNotNull('recurrence_from'))
            ->when(($criteria['kind'] ?? null) === 'ordinary', fn (Builder $query) => $query->whereNull('recurrence_from'))
            ->when(($criteria['from'] ?? null) !== null, fn (Builder $query) => $query->where('ends_at', '>=', $criteria['from']))
            ->when(($criteria['to'] ?? null) !== null, fn (Builder $query) => $query->where('starts_at', '<=', $criteria['to']))
            // the tasks that belong to the person: made by them or addressed to them by name
            ->when(! empty($criteria['mine']), fn (Builder $query) => $query->where(fn (Builder $mine) => $mine
                ->where('created_by', $viewerId)
                ->orWhereHas('assignments', fn (Builder $assignment) => $assignment->where('assignee_type', TaskAssignment::USER)->where('user_id', $viewerId))))
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page);
    }
}
