<?php

namespace App\Services;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\User;
use App\Repositories\Contracts\TaskSearchRepositoryInterface;
use App\Services\Contracts\TaskSearchServiceInterface;
use App\Services\Contracts\TaskServiceInterface;
use Carbon\CarbonImmutable;

/**
 * Searching tasks. What may be found is what the person may see (TaskService::visibilityScope, built from the signed-in
 * account): the search narrows it with a fixed set of filters and never widens it.
 */
class TaskSearchService implements TaskSearchServiceInterface
{
    public const PER_PAGE = 20;

    public const MAX_PER_PAGE = 50;

    public const MAX_QUERY = 100;

    public function __construct(
        private readonly TaskSearchRepositoryInterface $search,
        private readonly TaskServiceInterface $tasks,
    ) {
        //
    }

    public function search(User $actor, array $filters): array
    {
        // only a role of the structure (or the administrator) searches tasks; the others get nothing, not an empty list
        abort_unless($actor->role->hasPanel(), 403, 'Twoje konto nie ma dostępu do zadań.');

        $scope = $this->tasks->visibilityScope($actor);

        $criteria = [
            'q' => isset($filters['q']) ? mb_substr(trim((string) $filters['q']), 0, self::MAX_QUERY) : null,
            'status' => $this->known($filters['status'] ?? null, array_column(TaskStatus::cases(), 'value')),
            'priority' => $this->known($filters['priority'] ?? null, array_column(TaskPriority::cases(), 'value')),
            'kind' => $this->known($filters['kind'] ?? null, ['recurring', 'ordinary']),
            'mine' => filter_var($filters['mine'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'from' => $this->day($filters['from'] ?? null)?->startOfDay(),
            'to' => $this->day($filters['to'] ?? null)?->endOfDay(),
        ];

        $perPage = max(1, min((int) ($filters['per_page'] ?? self::PER_PAGE), self::MAX_PER_PAGE));
        $page = $this->search->search($scope, $actor->id, $criteria, max(1, (int) ($filters['page'] ?? 1)), $perPage);

        return [
            'data' => $this->tasks->present($actor, $page->items()),
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'total' => $page->total(),
            'per_page' => $page->perPage(),
        ];
    }

    /** @param  list<string>  $allowed */
    private function known(mixed $value, array $allowed): ?string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : null;
    }

    private function day(mixed $value): ?CarbonImmutable
    {
        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value, config('app.timezone')) : null;
    }
}
