<?php

namespace App\Repositories;

use App\Enums\HeadquarterType;
use App\Enums\UserRole;
use App\Models\ShopBranch;
use App\Models\ShopHeadquarter;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\TaskCompletion;
use App\Models\User;
use App\Models\WarehouseBranch;
use App\Models\WarehouseHeadquarter;
use App\Repositories\Concerns\ScopesTasks;
use App\Repositories\Contracts\TaskRepositoryInterface;
use App\Support\TaskAccessScope;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class EloquentTaskRepository implements TaskRepositoryInterface
{
    use ScopesTasks;

    private const LIMIT = 1000;

    public function visible(TaskAccessScope $scope, ?CarbonInterface $from = null, ?CarbonInterface $to = null): Collection
    {
        return $this->period($this->scoped($scope), $from, $to)->limit(self::LIMIT)->get();
    }

    public function visibleToAdministrator(?CarbonInterface $from = null, ?CarbonInterface $to = null): Collection
    {
        return $this->period($this->query(), $from, $to)->limit(self::LIMIT)->get();
    }

    public function findVisible(TaskAccessScope $scope, int $id): ?Task
    {
        return $this->scoped($scope)->whereKey($id)->first();
    }

    public function findForAdministrator(int $id): ?Task
    {
        return $this->query()->whereKey($id)->first();
    }

    public function create(array $attributes, array $assignments): Task
    {
        return DB::transaction(function () use ($attributes, $assignments) {
            // the organization and the author are set by the backend, never mass assigned
            $task = new Task(Arr::except($attributes, ['id', 'organization_id', 'created_by']));
            $task->forceFill(Arr::only($attributes, ['organization_id', 'created_by']))->save();
            $task->assignments()->createMany($this->normalized($assignments));

            return $this->fresh($task);
        });
    }

    public function update(Task $task, array $attributes, ?array $assignments = null): Task
    {
        return DB::transaction(function () use ($task, $attributes, $assignments) {
            // the organization, the author and the key never change with an ordinary update
            $task->update(Arr::except($attributes, ['id', 'organization_id', 'created_by']));

            if ($assignments !== null) {
                $task->assignments()->delete();
                $task->assignments()->createMany($this->normalized($assignments));
            }

            return $this->fresh($task);
        });
    }

    public function executors(Task $task): array
    {
        $assignments = $task->assignments;

        $query = User::query()
            ->where('organization_id', $task->organization_id)
            ->whereIn('role', $this->structureRoles());

        // the whole organization is everyone of the structure; otherwise the people of each target (a unit covers everyone below it)
        if (! $assignments->contains('assignee_type', TaskAssignment::ORGANIZATION)) {
            $query->where(function (Builder $targets) use ($assignments) {
                foreach ($assignments as $assignment) {
                    $targets->orWhere(fn (Builder $one) => match ($assignment->assignee_type) {
                        TaskAssignment::USER => $one->whereKey($assignment->user_id),
                        TaskAssignment::HEADQUARTERS => $assignment->shop_headquarter_id !== null
                            ? $one->where('shop_headquarter_id', $assignment->shop_headquarter_id)
                            : $one->where('warehouse_headquarter_id', $assignment->warehouse_headquarter_id),
                        default => $assignment->shop_branch_id !== null
                            ? $one->where('shop_branch_id', $assignment->shop_branch_id)
                            : $one->where('warehouse_branch_id', $assignment->warehouse_branch_id),
                    });
                }
            });
        }

        return $query->orderBy('name')->get(['id', 'name', 'role'])
            ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name, 'role' => $user->role->label()])
            ->values()
            ->all();
    }

    public function complete(Task $task, int $userId, string $occurrence = 'once'): Task
    {
        TaskCompletion::query()->firstOrCreate(['task_id' => $task->id, 'user_id' => $userId, 'occurrence_key' => $occurrence], ['completed_at' => now()]);

        return $this->fresh($task);
    }

    public function delete(Task $task): void
    {
        $task->delete();
    }

    public function dependsOnChain(int $organizationId, int $taskId, int $candidateId): bool
    {
        $seen = [];
        $currentId = $taskId;

        while ($currentId !== null && ! in_array($currentId, $seen, true)) {
            if ($currentId === $candidateId) {
                return true;
            }

            $seen[] = $currentId;
            $currentId = Task::query()->where('organization_id', $organizationId)->whereKey($currentId)->value('depends_on_id');
        }

        return false;
    }

    public function headquarters(int $organizationId, ?HeadquarterType $type = null, ?int $headquarterId = null): array
    {
        $this->requireType($type, [$headquarterId]);

        $rows = collect();

        foreach ([HeadquarterType::Shop, HeadquarterType::Warehouse] as $kind) {
            if ($type !== null && $type !== $kind) {
                continue;
            }

            $model = $kind === HeadquarterType::Shop ? ShopHeadquarter::class : WarehouseHeadquarter::class;

            $model::query()
                ->where('organization_id', $organizationId)
                ->when($headquarterId !== null, fn (Builder $query) => $query->whereKey($headquarterId))
                ->get(['id', 'name'])
                ->each(fn ($headquarters) => $rows->push(['type' => $kind->value, 'id' => $headquarters->id, 'name' => $headquarters->name]));
        }

        return $rows->sortBy('name')->values()->all();
    }

    public function branches(int $organizationId, ?HeadquarterType $type = null, ?int $headquarterId = null, ?int $branchId = null): array
    {
        $this->requireType($type, [$headquarterId, $branchId]);

        $rows = collect();

        foreach ([HeadquarterType::Shop, HeadquarterType::Warehouse] as $kind) {
            if ($type !== null && $type !== $kind) {
                continue;
            }

            $model = $kind === HeadquarterType::Shop ? ShopBranch::class : WarehouseBranch::class;
            $column = $kind->headquarterColumn();

            $model::query()
                ->where('organization_id', $organizationId)
                ->when($headquarterId !== null, fn (Builder $query) => $query->where($column, $headquarterId))
                ->when($branchId !== null, fn (Builder $query) => $query->whereKey($branchId))
                ->with('headquarter:id,name')
                ->get()
                ->each(fn ($branch) => $rows->push([
                    'type' => $kind->value,
                    'id' => $branch->id,
                    'name' => $branch->name,
                    'headquarter_id' => (int) $branch->{$column},
                    'headquarter' => (string) $branch->headquarter?->name,
                ]));
        }

        return $rows->sortBy('name')->values()->all();
    }

    public function people(int $organizationId, ?HeadquarterType $type = null, ?int $headquarterId = null, ?int $branchId = null): array
    {
        $this->requireType($type, [$headquarterId, $branchId]);

        $roles = collect(HeadquarterType::cases())
            ->when($type !== null, fn (Collection $kinds) => $kinds->filter(fn (HeadquarterType $kind) => $kind === $type))
            ->flatMap(fn (HeadquarterType $kind) => [$kind->ownerRole(), $kind->leaderRole(), $kind->workerRole()])
            ->all();

        return User::query()
            ->where('organization_id', $organizationId)
            ->whereIn('role', $roles)
            ->when($headquarterId !== null, fn (Builder $query) => $query->where($type->headquarterColumn(), $headquarterId))
            ->when($branchId !== null, fn (Builder $query) => $query->where($type->branchColumn(), $branchId))
            ->orderBy('name')
            ->get(['id', 'name', 'role'])
            ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name, 'role' => $user->role->label()])
            ->values()
            ->all();
    }

    /** Tasks with everything a row needs loaded (no organization filter: use scoped() or the administrator path). */
    private function query(): Builder
    {
        return Task::query()->with(self::ROW_RELATIONS)->orderBy('starts_at')->orderBy('id');
    }

    /** The tasks of the scope's organization that the scope lets the person see. */
    private function scoped(TaskAccessScope $scope): Builder
    {
        return $this->applyScope($this->query(), $scope);
    }

    private function period(Builder $query, ?CarbonInterface $from, ?CarbonInterface $to): Builder
    {
        return $query
            ->when($from !== null, fn (Builder $query) => $query->where('ends_at', '>=', $from))
            ->when($to !== null, fn (Builder $query) => $query->where('starts_at', '<=', $to));
    }

    /**
     * Only the columns of the target that fits the assignment type are kept, so a row can never name a second target.
     *
     * @param  list<array<string, mixed>>  $assignments
     * @return list<array<string, mixed>>
     */
    private function normalized(array $assignments): array
    {
        $columns = [
            TaskAssignment::ORGANIZATION => [],
            TaskAssignment::USER => ['user_id'],
            TaskAssignment::HEADQUARTERS => ['shop_headquarter_id', 'warehouse_headquarter_id'],
            TaskAssignment::BRANCH => ['shop_branch_id', 'warehouse_branch_id'],
        ];

        return array_map(function (array $row) use ($columns) {
            $type = $row['assignee_type'] ?? null;

            if (! is_string($type) || ! isset($columns[$type])) {
                throw new InvalidArgumentException('Nieznany typ adresata zadania.');
            }

            $row = Arr::only($row, ['assignee_type', ...$columns[$type]]);

            if ($type !== TaskAssignment::ORGANIZATION && count(array_filter($row, fn ($value) => $value !== null)) !== 2) {
                throw new InvalidArgumentException('Adresat zadania musi wskazywać dokładnie jeden cel.');
            }

            return $row;
        }, array_values($assignments));
    }

    /**
     * An id of a headquarters or branch says nothing without its type (shops and warehouses have separate tables).
     *
     * @param  list<?int>  $ids
     */
    private function requireType(?HeadquarterType $type, array $ids): void
    {
        if ($type === null && array_filter($ids, fn (?int $id) => $id !== null) !== []) {
            throw new InvalidArgumentException('Typ jednostki jest wymagany przy filtrowaniu po ID.');
        }
    }

    /**
     * @return list<UserRole>
     */
    private function structureRoles(): array
    {
        return collect(HeadquarterType::cases())
            ->flatMap(fn (HeadquarterType $kind) => [$kind->ownerRole(), $kind->leaderRole(), $kind->workerRole()])
            ->all();
    }

    private function fresh(Task $task): Task
    {
        return $task->fresh(self::ROW_RELATIONS);
    }
}
