<?php

namespace App\Repositories\Contracts;

use App\Enums\HeadquarterType;
use App\Models\Task;
use App\Support\TaskAccessScope;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

interface TaskRepositoryInterface
{
    /**
     * Tasks of ONE organization that the scope allows to see (the scope is built by the service from the signed-in
     * user), optionally only those overlapping the period, ordered by start, at most 1000. Every task comes with its
     * creator, its dependency and its assignments (with their targets) loaded.
     *
     * @return Collection<int, Task>
     */
    public function visible(TaskAccessScope $scope, ?CarbonInterface $from = null, ?CarbonInterface $to = null): Collection;

    /**
     * Tasks of every organization: only for the administrator of the platform (read-only), an explicit path so the
     * ordinary one can never lose its organization filter.
     *
     * @return Collection<int, Task>
     */
    public function visibleToAdministrator(?CarbonInterface $from = null, ?CarbonInterface $to = null): Collection;

    /** One task, only when the scope allows to see it (a task of another organization is never found). */
    public function findVisible(TaskAccessScope $scope, int $id): ?Task;

    public function findForAdministrator(int $id): ?Task;

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $assignments  rows of task_assignments (without task_id)
     */
    public function create(array $attributes, array $assignments): Task;

    /**
     * Updates the task and, when $assignments is given, replaces its assignments.
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>|null  $assignments
     */
    public function update(Task $task, array $attributes, ?array $assignments = null): Task;

    /**
     * The people who carry the task out: everyone of the structure (never the organization owners) in the targets of
     * its assignments, a unit covering everyone below it; the whole organization is everyone.
     *
     * @return list<array{id: int, name: string, role: string}>
     */
    public function executors(Task $task): array;

    /** Records that the person has done their part (once) and returns the task refreshed. */
    public function complete(Task $task, int $userId): Task;

    public function delete(Task $task): void;

    /**
     * Whether a task depends (directly or through other tasks) on the given one, i.e. the given task is in the
     * chain of its dependencies (only tasks of the organization are followed). Used to refuse circular dependencies.
     */
    public function dependsOnChain(int $organizationId, int $taskId, int $candidateId): bool;

    /**
     * Main headquarters of the organization (shops and warehouses), by name.
     *
     * @return list<array{type: string, id: int, name: string}>
     */
    public function headquarters(int $organizationId, ?HeadquarterType $type = null, ?int $headquarterId = null): array;

    /**
     * Branches of the organization (all, or of one headquarters), by name.
     *
     * @return list<array{type: string, id: int, name: string, headquarter_id: int, headquarter: string}>
     */
    public function branches(int $organizationId, ?HeadquarterType $type = null, ?int $headquarterId = null, ?int $branchId = null): array;

    /**
     * People of the shop / warehouse structure of the organization (never the organization owners), optionally only
     * those of one headquarters (with its branches) or of one branch, by name.
     *
     * @return list<array{id: int, name: string, role: string}>
     */
    public function people(int $organizationId, ?HeadquarterType $type = null, ?int $headquarterId = null, ?int $branchId = null): array;
}
