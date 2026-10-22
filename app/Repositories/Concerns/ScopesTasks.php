<?php

namespace App\Repositories\Concerns;

use App\Models\Task;
use App\Models\TaskAssignment;
use App\Support\TaskAccessScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * The one place that turns what a person may see (a TaskAccessScope, built only from the signed-in user) into a query:
 * always inside the scope's organization, and for anyone but the owner only the tasks they created and the tasks that
 * are for them, for their unit or for a unit below it. Everything that reads tasks for a person (the lists, the finding
 * of one task, the search) goes through it, so none of them can forget a rule.
 */
trait ScopesTasks
{
    /** What a row of a task list needs loaded. */
    private const ROW_RELATIONS = [
        'creator:id,name',
        'dependsOn:id,title',
        'completions:id,task_id,user_id,occurrence_key',
        'assignments.user:id,name',
        'assignments.shopHeadquarter:id,name',
        'assignments.warehouseHeadquarter:id,name',
        'assignments.shopBranch:id,name',
        'assignments.warehouseBranch:id,name',
    ];

    /**
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    private function applyScope(Builder $query, TaskAccessScope $scope): Builder
    {
        return $query
            ->where('organization_id', $scope->organizationId)
            ->when(! $scope->all, fn (Builder $query) => $query->where(fn (Builder $visible) => $visible
                ->where('created_by', $scope->userId)
                ->orWhereHas('assignments', fn (Builder $assignment) => $assignment->where(fn (Builder $target) => $target
                    ->where('assignee_type', TaskAssignment::ORGANIZATION)
                    ->orWhere(fn (Builder $user) => $user->where('assignee_type', TaskAssignment::USER)->whereIn('user_id', $scope->userIds))
                    ->orWhere(fn (Builder $unit) => $unit->where('assignee_type', TaskAssignment::HEADQUARTERS)->where(fn (Builder $ids) => $ids
                        ->whereIn('shop_headquarter_id', $scope->shopHeadquarterIds)
                        ->orWhereIn('warehouse_headquarter_id', $scope->warehouseHeadquarterIds)))
                    ->orWhere(fn (Builder $unit) => $unit->where('assignee_type', TaskAssignment::BRANCH)->where(fn (Builder $ids) => $ids
                        ->whereIn('shop_branch_id', $scope->shopBranchIds)
                        ->orWhereIn('warehouse_branch_id', $scope->warehouseBranchIds)))))));
    }
}
