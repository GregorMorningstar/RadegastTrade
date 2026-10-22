<?php

namespace App\Repositories\Contracts;

use App\Models\Task;
use App\Support\TaskAccessScope;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface TaskSearchRepositoryInterface
{
    /**
     * A page of the tasks matching the criteria among the ones the scope lets the person see (always inside the scope's
     * organization; a null scope is the administrator's read-only path over every organization). Newest first.
     *
     * @param  array{q?: ?string, status?: ?string, priority?: ?string, kind?: ?string, mine?: bool, from?: ?CarbonInterface, to?: ?CarbonInterface}  $criteria
     * @return LengthAwarePaginator<int, Task>
     */
    public function search(?TaskAccessScope $scope, int $viewerId, array $criteria, int $page, int $perPage): LengthAwarePaginator;
}
