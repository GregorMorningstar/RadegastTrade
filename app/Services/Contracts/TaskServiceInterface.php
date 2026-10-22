<?php

namespace App\Services\Contracts;

use App\Models\Task;
use App\Models\User;
use App\Support\TaskAccessScope;

interface TaskServiceInterface
{
    /**
     * What the task module shows the person: the tasks they may see (by their role and unit), whether they may
     * add tasks, the targets they may assign tasks to, and the labels of the priorities and statuses.
     *
     * Visibility follows the hierarchy: the organization owner sees every task of the organization, a headquarters
     * manager the tasks for the organization, for their headquarters, its branches and the people below; a branch
     * leader those for the organization, their headquarters, their branch and its people; a worker those that are
     * for them (themselves, their branch, their headquarters, the organization). Everyone also sees the tasks
     * they created. The platform administrator sees all tasks (read-only). 403 for roles without a panel.
     *
     * @return array{tasks: list<array<string, mixed>>, can_create: bool, targets: array<string, mixed>, priorities: list<array{value: string, label: string}>, statuses: list<array{value: string, label: string}>}
     */
    public function board(User $actor): array;

    /**
     * The visible tasks overlapping the period (all of them without a period).
     *
     * @return list<array<string, mixed>>
     */
    public function list(User $actor, ?string $from = null, ?string $to = null): array;

    /**
     * Who the person may assign tasks to: the organization owner the whole organization, every headquarters,
     * branch and person; a headquarters manager their headquarters, its branches and people; a branch leader their
     * branch and its people. Workers and the administrator cannot assign tasks (403).
     *
     * @return array{organization: bool, headquarters: list<array<string, mixed>>, branches: list<array<string, mixed>>, people: list<array<string, mixed>>}
     */
    public function targets(User $actor): array;

    /**
     * Adds a task. Only the organization owner, a headquarters manager and a branch leader may; the targets have to
     * be within the person's targets (see targets()).
     *
     * @param  array<string, mixed>  $data  title, description, priority, status, progress, is_milestone, all_day, starts_at, ends_at, depends_on_id, assignments
     * @return array<string, mixed> the new task
     */
    public function create(User $actor, array $data): array;

    /**
     * Step one of finishing a task that asks for a confirmation by an e-mailed code: e-mails a 6-digit code to the person
     * who is about to mark it as done (only a person who may complete it right now). Nothing is recorded yet.
     *
     * @return array{pending: bool, title: string, valid_minutes: int}
     */
    public function requestCompletionCode(User $actor, int $taskId): array;

    /**
     * Step two: the code from the e-mail records the person's part (a standing task: today's day) as done. Five wrong
     * codes, another day or the passing of the time drop the code.
     *
     * @return array<string, mixed> the task
     */
    public function completeWithCode(User $actor, int $taskId, string $code): array;

    /**
     * What the person may see, as a scope built only from their account (null for the administrator, who sees every
     * organization read-only); 403 for an account without a place in the structure.
     */
    public function visibilityScope(User $actor): ?TaskAccessScope;

    /**
     * The tasks as the person sees them (what they may do with each, who has done their part).
     *
     * @param  iterable<Task>  $tasks
     * @return list<array<string, mixed>>
     */
    public function present(User $actor, iterable $tasks): array;

    /**
     * Changes a task. Its creator and the owner of the organization may change everything; anyone else who sees the
     * task may change only its status and progress (403 for the rest).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed> the updated task
     */
    public function update(User $actor, int $taskId, array $data): array;

    /**
     * Moves the task in time (dragging in the calendar or on the Gantt chart): its creator and the owner of the
     * organization only.
     *
     * @param  array<string, mixed>  $data  starts_at, ends_at, all_day
     * @return array<string, mixed> the updated task
     */
    public function reschedule(User $actor, int $taskId, array $data): array;

    /**
     * Deletes a task (its creator and the owner of the organization only).
     */
    public function delete(User $actor, int $taskId): void;
}
