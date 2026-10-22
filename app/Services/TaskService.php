<?php

namespace App\Services;

use App\Enums\HeadquarterType;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\UserRole;
use App\Models\ApiKey;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Notifications\TaskCompletionCodeNotification;
use App\Models\User;
use App\Repositories\Contracts\TaskRepositoryInterface;
use App\Services\Contracts\TaskServiceInterface;
use App\Support\TaskAccessScope;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Tasks of the organization, scheduled in the calendar and on the Gantt chart. Who sees a task and who may change it
 * follows the hierarchy: organization → headquarters → branch → person.
 */
class TaskService implements TaskServiceInterface
{
    /** Wrong e-mail codes allowed before the code of a completion is dropped. */
    private const COMPLETION_ATTEMPTS = 5;

    /** The longest standing task: two years (the weekdays and the hours repeat inside it). */
    private const MAX_RECURRENCE_DAYS = 731;

    /** What a person is in the structure of the organization. */
    private const ADMIN = 'admin';

    private const OWNER = 'owner';

    private const MANAGER = 'manager';

    private const LEADER = 'leader';

    private const WORKER = 'worker';

    /** @var array<string, list<array{id: int, name: string, role: string}>> */
    private array $executorCache = [];

    public function __construct(private readonly TaskRepositoryInterface $tasks)
    {
        //
    }

    public function board(User $actor): array
    {
        $context = $this->context($actor);
        $canCreate = $this->canCreate($actor);

        return [
            'tasks' => $this->list($actor),
            'can_create' => $canCreate,
            'targets' => $canCreate ? $this->targets($actor) : ['organization' => false, 'headquarters' => [], 'branches' => [], 'people' => []],
            'priorities' => collect(TaskPriority::cases())->map(fn (TaskPriority $priority) => ['value' => $priority->value, 'label' => $priority->label()])->all(),
            'statuses' => collect(TaskStatus::cases())->map(fn (TaskStatus $status) => ['value' => $status->value, 'label' => $status->label()])->all(),
        ];
    }

    public function list(User $actor, ?string $from = null, ?string $to = null): array
    {
        $context = $this->context($actor);

        $scope = $this->scope($actor, $context);
        $from = $from ? $this->date($from) : null;
        $to = $to ? $this->date($to) : null;

        return ($scope === null ? $this->tasks->visibleToAdministrator($from, $to) : $this->tasks->visible($scope, $from, $to))
            ->map(fn (Task $task) => $this->payload($actor, $context, $task))
            ->values()
            ->all();
    }

    public function targets(User $actor): array
    {
        $context = $this->context($actor);
        abort_unless($this->canCreate($actor), 403, 'Nie możesz przydzielać zadań.');

        $organizationId = $actor->organization_id;
        /** @var HeadquarterType|null $type */
        $type = $context['type'];

        return match ($context['kind']) {
            self::OWNER => [
                'organization' => true,
                'headquarters' => $this->tasks->headquarters($organizationId),
                'branches' => $this->tasks->branches($organizationId),
                'people' => $this->tasks->people($organizationId),
            ],
            self::MANAGER => [
                'organization' => false,
                'headquarters' => $this->tasks->headquarters($organizationId, $type, $context['headquarter']),
                'branches' => $this->tasks->branches($organizationId, $type, $context['headquarter']),
                'people' => $this->tasks->people($organizationId, $type, $context['headquarter']),
            ],
            default => [
                'organization' => false,
                'headquarters' => [],
                'branches' => $this->tasks->branches($organizationId, $type, $context['headquarter'], $context['branch']),
                'people' => $this->tasks->people($organizationId, $type, null, $context['branch']),
            ],
        };
    }

    public function create(User $actor, array $data): array
    {
        [$context, $attributes, $assignments] = $this->prepareCreation($actor, $data);

        return $this->payload($actor, $context, $this->tasks->create($attributes, $assignments));
    }

    /**
     * Everything that adding a task checks, without saving anything: the right to add, the schedule, the series, the
     * dependency and the targets. Used by the adding itself and by the step that only asks for the e-mail code, so a task
     * that could not be added never costs a code.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: array{kind: string, type: ?HeadquarterType, headquarter: ?int, branch: ?int}, 1: array<string, mixed>, 2: list<array<string, mixed>>}
     */
    private function prepareCreation(User $actor, array $data): array
    {
        $context = $this->context($actor);
        abort_unless($this->canCreate($actor), 403, 'Nie możesz dodawać zadań.');

        $milestone = (bool) ($data['is_milestone'] ?? false);
        $attributes = $this->attributes($data) + ['organization_id' => $actor->organization_id, 'created_by' => $actor->id, 'is_milestone' => $milestone];

        if (is_array($data['recurrence'] ?? null)) {
            // a standing task: its span, weekdays and daily hours come from the recurrence; it has no status of its own
            $attributes = [...$attributes, ...$this->recurrence($data['recurrence'], $milestone), 'status' => TaskStatus::Todo, 'progress' => 0];
        } else {
            $attributes = [...$attributes, ...$this->schedule($data, $milestone)];
        }

        $attributes['depends_on_id'] = $this->dependency($actor, $context, $data['depends_on_id'] ?? null, null);

        return [$context, $attributes, $this->assignments($actor, $data['assignments'] ?? [])];
    }

    public function requestCompletionCode(User $actor, int $taskId): array
    {
        $context = $this->context($actor);
        $task = $this->visibleTask($actor, $context, $taskId);
        $this->assertCanCompleteWithCode($actor, $context, $task);

        $code = (string) random_int(100000, 999999);
        $key = self::completionKey($actor->id, $task->id);
        $until = now()->addMinutes(TaskCompletionCodeNotification::VALID_MINUTES);

        // the code is for this person, this task and this day (a standing task is done day by day)
        Cache::put($key, [
            'hash' => ApiKey::hashOf($code),
            'day' => CarbonImmutable::now(config('app.timezone'))->toDateString(),
            'attempts' => 0,
            'expires_at' => $until->getTimestamp(),
        ], $until);

        try {
            $actor->notify(new TaskCompletionCodeNotification($code, $task->title));
        } catch (\Throwable $e) {
            Cache::forget($key);

            throw $e;
        }

        return ['pending' => true, 'title' => $task->title, 'valid_minutes' => TaskCompletionCodeNotification::VALID_MINUTES];
    }

    public function completeWithCode(User $actor, int $taskId, string $code): array
    {
        $context = $this->context($actor);
        $task = $this->visibleTask($actor, $context, $taskId);
        $this->assertCanCompleteWithCode($actor, $context, $task);

        $key = self::completionKey($actor->id, $task->id);
        $pending = Cache::get($key);

        if (! is_array($pending) || ($pending['expires_at'] ?? 0) < now()->getTimestamp() || ($pending['day'] ?? null) !== CarbonImmutable::now(config('app.timezone'))->toDateString()) {
            Cache::forget($key);

            throw ValidationException::withMessages(['code' => 'Kod wygasł albo nie został wysłany. Poproś o nowy kod.']);
        }

        if (! hash_equals($pending['hash'], ApiKey::hashOf(trim($code)))) {
            $pending['attempts']++;

            if ($pending['attempts'] >= self::COMPLETION_ATTEMPTS) {
                Cache::forget($key);

                throw ValidationException::withMessages(['code' => 'Zbyt wiele błędnych kodów. Poproś o nowy kod.']);
            }

            Cache::put($key, $pending, now()->addSeconds(max(1, $pending['expires_at'] - now()->getTimestamp())));

            throw ValidationException::withMessages(['code' => 'Nieprawidłowy kod.']);
        }

        Cache::forget($key);

        // the code proves that the person who carries the task out has received it; the part is then recorded as usual
        return $this->payload($actor, $context, $this->finish($actor, $task));
    }

    /**
     * The task has to ask for the code, and the person has to be one who may mark it as done (an executor on a day of a
     * standing task, or whoever may finish it): the code is never a way around who may complete what.
     *
     * @param  array{kind: string, type: ?HeadquarterType, headquarter: ?int, branch: ?int}  $context
     */
    private function assertCanCompleteWithCode(User $actor, array $context, Task $task): void
    {
        if (! $task->requires_completion_code) {
            throw ValidationException::withMessages(['task' => 'To zadanie nie wymaga potwierdzenia kodem: oznacz je jako wykonane zwykłym przyciskiem.']);
        }

        abort_unless($this->canComplete($actor, $context, $task), 403, 'Nie możesz oznaczyć tego zadania jako wykonanego: dziś nie jest jego dniem, zrobiłeś je już, nie jesteś jego wykonawcą albo nie masz do tego prawa.');
    }

    private static function completionKey(int $userId, int $taskId): string
    {
        return 'task-completion:'.$userId.':'.$taskId;
    }

    public function visibilityScope(User $actor): ?TaskAccessScope
    {
        // 403 for an account that has no place in the structure (no panel, no organization)
        return $this->scope($actor, $this->context($actor));
    }

    public function present(User $actor, iterable $tasks): array
    {
        $context = $this->context($actor);

        return collect($tasks)->map(fn (Task $task) => $this->payload($actor, $context, $task))->values()->all();
    }

    public function update(User $actor, int $taskId, array $data): array
    {
        $context = $this->context($actor);
        $task = $this->visibleTask($actor, $context, $taskId);

        // a task that asks for the e-mail code is finished only with it (requestCompletionCode / completeWithCode)
        if ($task->requires_completion_code && ($data['status'] ?? null) === TaskStatus::Done->value && $task->status !== TaskStatus::Done) {
            throw ValidationException::withMessages(['status' => 'To zadanie wymaga potwierdzenia wykonania kodem z e-maila: poproś o kod przyciskiem „Wykonane”.']);
        }

        // a standing task is done day by day: "done" records today's occurrence for the person, it never finishes the series
        if ($task->isRecurring() && ($data['status'] ?? null) === TaskStatus::Done->value) {
            abort_unless($this->canComplete($actor, $context, $task), 403, 'Nie możesz oznaczyć tego zadania jako wykonanego: dziś nie jest jego dniem, zrobiłeś je już dzisiaj albo nie jesteś jego wykonawcą.');

            return $this->payload($actor, $context, $this->finish($actor, $task));
        }

        // everyone else who sees the task may only mark it as done (a milestone: only a leader or an owner accepts it);
        // the rest is for its creator and the owner of the organization
        if (! $this->canEdit($actor, $context, $task)) {
            abort_if($context['kind'] === self::ADMIN, 403, 'Administrator ma tylko podgląd zadań.');
            abort_if(array_diff(array_keys($data), ['status']) !== [] || ($data['status'] ?? null) !== TaskStatus::Done->value, 403, 'Możesz tylko oznaczyć to zadanie jako zakończone.');
            abort_unless($this->canComplete($actor, $context, $task), 403, 'Nie możesz oznaczyć tego zadania jako zakończonego (kamień milowy akceptuje tylko kierownik placówki lub siedziby albo właściciel organizacji).');

            return $this->payload($actor, $context, $this->finish($actor, $task));
        }

        $attributes = $this->attributes($data, partial: true);
        $scheduleKeys = array_intersect(['starts_at', 'ends_at', 'all_day', 'is_milestone'], array_keys($data));

        if ($task->isRecurring()) {
            // a series has no status or progress of its own (each day is done by each person separately)
            unset($attributes['status'], $attributes['progress']);
        }

        if (is_array($data['recurrence'] ?? null)) {
            // (re)defining the series: the span, the weekdays and the hours replace the schedule
            $attributes = [...$attributes, ...$this->recurrence($data['recurrence'], (bool) ($data['is_milestone'] ?? false)), 'status' => TaskStatus::Todo, 'progress' => 0];
            $scheduleKeys = [];
        } elseif ($task->isRecurring() && array_key_exists('recurrence', $data)) {
            // back to an ordinary task: the series is dropped and the schedule given (or the series' own span) stays
            $attributes = [...$attributes, 'recurrence_from' => null, 'recurrence_to' => null, 'recurrence_days' => null, 'daily_start_time' => null, 'daily_end_time' => null];
        } elseif ($task->isRecurring() && $scheduleKeys !== []) {
            throw ValidationException::withMessages(['starts_at' => 'Termin zadania stałego zmienia się przez jego powtarzanie (okres, dni tygodnia, godziny).']);
        }

        if ($scheduleKeys !== []) {
            $merged = [
                'starts_at' => $data['starts_at'] ?? $task->starts_at,
                'ends_at' => array_key_exists('ends_at', $data) ? $data['ends_at'] : $task->ends_at,
                'all_day' => $data['all_day'] ?? $task->all_day,
            ];
            $attributes = [...$attributes, ...$this->schedule($merged, (bool) ($data['is_milestone'] ?? $task->is_milestone)), 'is_milestone' => (bool) ($data['is_milestone'] ?? $task->is_milestone)];
        }

        if (array_key_exists('depends_on_id', $data)) {
            $attributes['depends_on_id'] = $this->dependency($actor, $context, $data['depends_on_id'], $task->id);
        }

        $assignments = array_key_exists('assignments', $data) ? $this->assignments($actor, $data['assignments']) : null;

        return $this->payload($actor, $context, $this->tasks->update($task, $attributes, $assignments));
    }

    public function reschedule(User $actor, int $taskId, array $data): array
    {
        $context = $this->context($actor);
        $task = $this->visibleTask($actor, $context, $taskId);
        abort_unless($this->canEdit($actor, $context, $task), 403, 'Nie możesz zmienić terminu tego zadania.');

        // dragging would move one day only and break the series: its period, weekdays and hours are changed in the form
        if ($task->isRecurring()) {
            throw ValidationException::withMessages(['starts_at' => 'Termin zadania stałego zmienia się w jego formularzu (okres, dni tygodnia, godziny), nie przeciąganiem.']);
        }

        $schedule = $this->schedule(
            ['starts_at' => $data['starts_at'], 'ends_at' => $data['ends_at'] ?? null, 'all_day' => $data['all_day'] ?? $task->all_day],
            $task->is_milestone,
        );

        return $this->payload($actor, $context, $this->tasks->update($task, $schedule));
    }

    public function delete(User $actor, int $taskId): void
    {
        $context = $this->context($actor);
        $task = $this->visibleTask($actor, $context, $taskId);
        abort_unless($this->canEdit($actor, $context, $task), 403, 'Nie możesz usunąć tego zadania.');

        $this->tasks->delete($task);
    }

    /**
     * What the person is in the structure: kind, their organization, headquarters type, headquarters and branch.
     *
     * @return array{kind: string, type: ?HeadquarterType, headquarter: ?int, branch: ?int}
     */
    private function context(User $actor): array
    {
        abort_unless($actor->role->hasPanel(), 403);

        if ($actor->role === UserRole::Admin) {
            return ['kind' => self::ADMIN, 'type' => null, 'headquarter' => null, 'branch' => null];
        }

        abort_if($actor->organization_id === null, 403);

        if ($actor->role === UserRole::ORGANIZATION_OWNER) {
            return ['kind' => self::OWNER, 'type' => null, 'headquarter' => null, 'branch' => null];
        }

        foreach (HeadquarterType::cases() as $type) {
            $kind = match ($actor->role) {
                $type->ownerRole() => self::MANAGER,
                $type->leaderRole() => self::LEADER,
                $type->workerRole() => self::WORKER,
                default => null,
            };

            if ($kind !== null) {
                return [
                    'kind' => $kind,
                    'type' => $type,
                    'headquarter' => $actor->{$type->headquarterColumn()},
                    'branch' => $actor->{$type->branchColumn()},
                ];
            }
        }

        abort(403);
    }

    /** Decided by TaskPolicy. */
    private function canCreate(User $actor): bool
    {
        return Gate::forUser($actor)->allows('create', Task::class);
    }

    /**
     * The creator and the owner of the organization change everything (TaskPolicy).
     *
     * @param  array{kind: string, type: ?HeadquarterType, headquarter: ?int, branch: ?int}  $context
     */
    private function canEdit(User $actor, array $context, Task $task): bool
    {
        return Gate::forUser($actor)->allows('update', $task);
    }

    /**
     * What the person sees, always inside their own organization (null for the administrator, who has a read-only path
     * over every organization). Built only from the signed-in user, never from request data.
     *
     * @param  array{kind: string, type: ?HeadquarterType, headquarter: ?int, branch: ?int}  $context
     */
    private function scope(User $actor, array $context): ?TaskAccessScope
    {
        if ($context['kind'] === self::ADMIN) {
            return null;
        }

        $organizationId = (int) $actor->organization_id;

        if ($context['kind'] === self::OWNER) {
            return new TaskAccessScope($organizationId, $actor->id, all: true, userIds: [$actor->id]);
        }

        /** @var HeadquarterType $type */
        $type = $context['type'];
        $headquarters = $context['headquarter'] !== null ? [$context['headquarter']] : [];
        $branches = $context['branch'] !== null ? [$context['branch']] : [];
        $userIds = [$actor->id];

        // what is below: a headquarters manager's branches and people, a branch leader's people
        if ($context['kind'] === self::MANAGER && $context['headquarter'] !== null) {
            $branches = collect($this->tasks->branches($organizationId, $type, $context['headquarter']))->pluck('id')->all();
            $userIds = [...$userIds, ...collect($this->tasks->people($organizationId, $type, $context['headquarter']))->pluck('id')->all()];
        }

        if ($context['kind'] === self::LEADER && $context['branch'] !== null) {
            $userIds = [...$userIds, ...collect($this->tasks->people($organizationId, $type, null, $context['branch']))->pluck('id')->all()];
        }

        return new TaskAccessScope(
            $organizationId,
            $actor->id,
            userIds: array_values(array_unique($userIds)),
            shopHeadquarterIds: $type === HeadquarterType::Shop ? $headquarters : [],
            warehouseHeadquarterIds: $type === HeadquarterType::Warehouse ? $headquarters : [],
            shopBranchIds: $type === HeadquarterType::Shop ? $branches : [],
            warehouseBranchIds: $type === HeadquarterType::Warehouse ? $branches : [],
        );
    }

    /**
     * A task the person sees (404 otherwise: a task they may not see does not exist for them).
     *
     * @param  array{kind: string, type: ?HeadquarterType, headquarter: ?int, branch: ?int}  $context
     */
    private function visibleTask(User $actor, array $context, int $taskId): Task
    {
        $task = $this->findTask($actor, $context, $taskId);

        abort_if($task === null, 404);

        return $task;
    }

    /**
     * @param  array{kind: string, type: ?HeadquarterType, headquarter: ?int, branch: ?int}  $context
     */
    private function findTask(User $actor, array $context, int $taskId): ?Task
    {
        $scope = $this->scope($actor, $context);

        return $scope === null ? $this->tasks->findForAdministrator($taskId) : $this->tasks->findVisible($scope, $taskId);
    }

    /**
     * @param  array{kind: string, type: ?HeadquarterType, headquarter: ?int, branch: ?int}  $context
     * @return array<string, mixed>
     */
    private function payload(User $actor, array $context, Task $task): array
    {
        $canEdit = $this->canEdit($actor, $context, $task);
        $executors = $this->executors($task);
        $today = CarbonImmutable::now(config('app.timezone'));
        // an ordinary task is done once; a standing one day by day, so the counts are those of today's occurrence
        $doneIds = $task->completions->where('occurrence_key', $task->isRecurring() ? $today->toDateString() : 'once')->pluck('user_id')->all();
        $oversees = $this->overseenIds($actor, $context);

        return [
            'id' => $task->id,
            'barcode' => $task->barcode,
            'title' => $task->title,
            'description' => $task->description,
            'priority' => $task->priority->value,
            'priority_label' => $task->priority->label(),
            'status' => $task->status->value,
            'status_label' => $task->status->label(),
            'progress' => $task->progress,
            'is_milestone' => $task->is_milestone,
            'all_day' => $task->all_day,
            'requires_completion_code' => $task->requires_completion_code,
            // a standing task: the period, the weekdays (ISO, 1 = Monday), the daily hours (null = the whole day)
            'recurrence' => $task->isRecurring() ? [
                'from' => $task->recurrence_from->toDateString(),
                'to' => $task->recurrence_to->toDateString(),
                'days' => array_map('intval', $task->recurrence_days ?? []),
                'start_time' => $task->daily_start_time ? substr((string) $task->daily_start_time, 0, 5) : null,
                'end_time' => $task->daily_end_time ? substr((string) $task->daily_end_time, 0, 5) : null,
                'label' => $this->recurrenceLabel($task),
            ] : null,
            'occurs_today' => $task->occursOn($today),
            // the days the person has marked as done (a standing task)
            'done_dates' => $task->isRecurring() ? $task->completions->where('user_id', $actor->id)->pluck('occurrence_key')->sort()->values()->all() : [],
            'starts_at' => $task->starts_at->toIso8601String(),
            'ends_at' => $task->ends_at->toIso8601String(),
            'depends_on_id' => $task->depends_on_id,
            'depends_on_title' => $task->dependsOn?->title,
            'created_by' => $task->creator ? ['id' => $task->creator->id, 'name' => $task->creator->name] : null,
            'assignees' => $task->assignments->map(fn (TaskAssignment $assignment) => $this->assignee($assignment))->values()->all(),
            // what the person may do with it
            'can_edit' => $canEdit,
            'can_complete' => $this->canComplete($actor, $context, $task),
            // the people who carry the task out: the counts for everyone, the names for the ones above them
            'is_executor' => collect($executors)->contains('id', $actor->id),
            'my_done' => in_array($actor->id, $doneIds, true),
            'executors_total' => count($executors),
            'executors_done' => collect($executors)->whereIn('id', $doneIds)->count(),
            'executors' => $oversees === [] && $context['kind'] !== self::ADMIN
                ? []
                : collect($executors)
                    ->filter(fn (array $person) => $oversees === null || in_array($person['id'], $oversees, true))
                    ->map(fn (array $person) => [...$person, 'done' => in_array($person['id'], $doneIds, true)])
                    ->values()
                    ->all(),
        ];
    }

    /**
     * People who carry the task out (resolved once per set of targets during the request).
     *
     * @return list<array{id: int, name: string, role: string}>
     */
    private function executors(Task $task): array
    {
        $key = $task->organization_id.':'.$task->assignments->map(fn (TaskAssignment $row) => $row->only(['assignee_type', 'user_id', 'shop_headquarter_id', 'warehouse_headquarter_id', 'shop_branch_id', 'warehouse_branch_id']))->toJson();

        return $this->executorCache[$key] ??= $this->tasks->executors($task);
    }

    /**
     * The people whose part the person may see: everyone for the owner and the administrator (null), the people below
     * a headquarters manager or a branch leader, nobody for a worker.
     *
     * @param  array{kind: string, type: ?HeadquarterType, headquarter: ?int, branch: ?int}  $context
     * @return list<int>|null
     */
    private function overseenIds(User $actor, array $context): ?array
    {
        return match ($context['kind']) {
            self::ADMIN, self::OWNER => null,
            self::MANAGER, self::LEADER => $this->scope($actor, $context)?->userIds ?? [],
            default => [],
        };
    }

    /**
     * Marks the task as done. A person who carries it out records their own part (the progress follows, and the task
     * is done when everyone has finished); anyone else who may complete it (a superior, a milestone acceptance)
     * finishes the whole task.
     */
    private function finish(User $actor, Task $task): Task
    {
        $executors = $this->executors($task);

        // a standing task: today's occurrence of this person only; the series itself is never finished
        if ($task->isRecurring()) {
            return $this->tasks->complete($task, $actor->id, CarbonImmutable::now(config('app.timezone'))->toDateString());
        }

        if ($task->is_milestone || ! collect($executors)->contains('id', $actor->id)) {
            return $this->tasks->update($task, ['status' => TaskStatus::Done]);
        }

        $task = $this->tasks->complete($task, $actor->id);
        $done = collect($executors)->whereIn('id', $task->completions->pluck('user_id')->all())->count();

        return $done >= count($executors)
            ? $this->tasks->update($task, ['status' => TaskStatus::Done])
            : $this->tasks->update($task, ['status' => TaskStatus::InProgress, 'progress' => (int) round($done / count($executors) * 100)]);
    }

    /**
     * @return array{type: string, label: string, id: ?int, unit_type: ?string}
     */
    private function assignee(TaskAssignment $assignment): array
    {
        return match ($assignment->assignee_type) {
            TaskAssignment::ORGANIZATION => ['type' => 'organization', 'label' => 'Cała organizacja', 'id' => null, 'unit_type' => null],
            TaskAssignment::HEADQUARTERS => $assignment->shop_headquarter_id !== null
                ? ['type' => 'headquarters', 'label' => 'Siedziba: '.$assignment->shopHeadquarter?->name, 'id' => $assignment->shop_headquarter_id, 'unit_type' => HeadquarterType::Shop->value]
                : ['type' => 'headquarters', 'label' => 'Siedziba: '.$assignment->warehouseHeadquarter?->name, 'id' => $assignment->warehouse_headquarter_id, 'unit_type' => HeadquarterType::Warehouse->value],
            TaskAssignment::BRANCH => $assignment->shop_branch_id !== null
                ? ['type' => 'branch', 'label' => 'Placówka: '.$assignment->shopBranch?->name, 'id' => $assignment->shop_branch_id, 'unit_type' => HeadquarterType::Shop->value]
                : ['type' => 'branch', 'label' => 'Placówka: '.$assignment->warehouseBranch?->name, 'id' => $assignment->warehouse_branch_id, 'unit_type' => HeadquarterType::Warehouse->value],
            default => ['type' => 'user', 'label' => (string) $assignment->user?->name, 'id' => $assignment->user_id, 'unit_type' => null],
        };
    }

    /**
     * The attributes of the task from the request data (all of them, or only the given ones).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data, bool $partial = false): array
    {
        $attributes = [];

        foreach (['title', 'description'] as $field) {
            if (! $partial || array_key_exists($field, $data)) {
                $attributes[$field] = isset($data[$field]) ? trim((string) $data[$field]) : null;
            }
        }

        if (! $partial || isset($data['priority'])) {
            $attributes['priority'] = TaskPriority::from($data['priority'] ?? TaskPriority::Normal->value);
        }

        if (! $partial || isset($data['status'])) {
            $attributes['status'] = TaskStatus::from($data['status'] ?? TaskStatus::Todo->value);
        }

        // the task asks the person who carries it out for a code from their e-mail before it counts as done (off by default)
        if (! $partial || array_key_exists('requires_completion_code', $data)) {
            $attributes['requires_completion_code'] = (bool) ($data['requires_completion_code'] ?? false);
        }

        if (! $partial || isset($data['progress'])) {
            $attributes['progress'] = max(0, min(100, (int) ($data['progress'] ?? 0)));
        }

        return $attributes;
    }

    /**
     * Whether the person may mark the task as done without being its creator or the owner: not the administrator, not
     * a finished task, and a milestone only by a leader of a branch, a manager of a headquarters or the owner.
     *
     * @param  array{kind: string, type: ?HeadquarterType, headquarter: ?int, branch: ?int}  $context
     */
    private function canComplete(User $actor, array $context, Task $task): bool
    {
        if ($context['kind'] === self::ADMIN) {
            return false;
        }

        // a standing task: on its days, for the people who carry it out, once a day each
        if ($task->isRecurring()) {
            $today = CarbonImmutable::now(config('app.timezone'));

            return $task->occursOn($today)
                && collect($this->executors($task))->contains('id', $actor->id)
                && ! $task->completions->where('occurrence_key', $today->toDateString())->contains('user_id', $actor->id);
        }

        // nothing to do for a finished task and for a person whose part is already done
        if ($task->status === TaskStatus::Done || $task->completions->contains('user_id', $actor->id)) {
            return false;
        }

        return ! $task->is_milestone || in_array($context['kind'], [self::OWNER, self::MANAGER, self::LEADER], true);
    }

    /**
     * A standing task: the weekdays (ISO, 1 = Monday ... 7 = Sunday) it repeats on between two dates, with optional hours
     * for each day (both or none; none = the whole day). The task's own start and end become the first and the last moment of
     * the series, so everything that works with a start and an end (the period filter, the Gantt chart) keeps working.
     *
     * @param  array<string, mixed>  $input  from, to (Y-m-d), days, start_time, end_time (H:i)
     * @return array<string, mixed>
     */
    private function recurrence(array $input, bool $milestone): array
    {
        if ($milestone) {
            throw ValidationException::withMessages(['recurrence' => 'Kamień milowy jest jednym momentem, więc nie może się powtarzać.']);
        }

        $zone = config('app.timezone');
        $from = CarbonImmutable::parse((string) ($input['from'] ?? ''), $zone)->startOfDay();
        $to = CarbonImmutable::parse((string) ($input['to'] ?? ''), $zone)->startOfDay();
        $days = collect($input['days'] ?? [])->map(fn ($day) => (int) $day)->filter(fn (int $day) => $day >= 1 && $day <= 7)->unique()->sort()->values()->all();

        if ($to->lt($from)) {
            throw ValidationException::withMessages(['recurrence.to' => 'Koniec okresu nie może być przed jego początkiem.']);
        }

        if ($from->diffInDays($to) > self::MAX_RECURRENCE_DAYS) {
            throw ValidationException::withMessages(['recurrence.to' => 'Zadanie stałe może trwać najwyżej '.self::MAX_RECURRENCE_DAYS.' dni.']);
        }

        if ($days === []) {
            throw ValidationException::withMessages(['recurrence.days' => 'Wybierz co najmniej jeden dzień tygodnia.']);
        }

        $start = ($input['start_time'] ?? null) ?: null;
        $end = ($input['end_time'] ?? null) ?: null;

        if (($start === null) !== ($end === null)) {
            throw ValidationException::withMessages(['recurrence.start_time' => 'Podaj godzinę rozpoczęcia i zakończenia albo żadnej (zadanie na cały dzień).']);
        }

        if ($start !== null && $end <= $start) {
            throw ValidationException::withMessages(['recurrence.end_time' => 'Godzina zakończenia musi być po godzinie rozpoczęcia.']);
        }

        // the first and the last day the series really falls on
        $first = null;
        $last = null;

        for ($day = $from; $day->lte($to); $day = $day->addDay()) {
            if (in_array($day->dayOfWeekIso, $days, true)) {
                $first ??= $day;
                $last = $day;
            }
        }

        if ($first === null) {
            throw ValidationException::withMessages(['recurrence.days' => 'Wybrane dni tygodnia nie wypadają w podanym okresie.']);
        }

        return [
            'recurrence_from' => $from->toDateString(),
            'recurrence_to' => $to->toDateString(),
            'recurrence_days' => $days,
            // stored as a time of day with seconds (the same form on every database)
            'daily_start_time' => $start === null ? null : $start.':00',
            'daily_end_time' => $end === null ? null : $end.':00',
            'is_milestone' => false,
            // no hours = a whole-day task; with hours the day has a window
            'all_day' => $start === null,
            'starts_at' => $start === null ? $first->startOfDay() : $first->setTimeFromTimeString($start),
            'ends_at' => $end === null ? $last->endOfDay() : $last->setTimeFromTimeString($end),
        ];
    }

    /** The series in words: "w dni robocze od 01.11.2026 do 30.11.2026, godz. 08:00–10:00". */
    private function recurrenceLabel(Task $task): string
    {
        $days = array_map('intval', $task->recurrence_days ?? []);
        $names = [1 => 'Pn', 2 => 'Wt', 3 => 'Śr', 4 => 'Cz', 5 => 'Pt', 6 => 'So', 7 => 'Nd'];

        $which = match (true) {
            $days === [1, 2, 3, 4, 5, 6, 7] => 'codziennie',
            $days === [1, 2, 3, 4, 5] => 'w dni robocze',
            default => implode(', ', array_map(fn (int $day) => $names[$day], $days)),
        };

        $hours = $task->daily_start_time ? 'godz. '.substr((string) $task->daily_start_time, 0, 5).'–'.substr((string) $task->daily_end_time, 0, 5) : 'cały dzień';

        return $which.' od '.$task->recurrence_from->format('d.m.Y').' do '.$task->recurrence_to->format('d.m.Y').', '.$hours;
    }

    /**
     * The start and the end of the task: a milestone is one moment, an all-day task spans whole days.
     *
     * @param  array<string, mixed>  $data  starts_at, ends_at, all_day
     * @return array{starts_at: CarbonInterface, ends_at: CarbonInterface, all_day: bool}
     */
    private function schedule(array $data, bool $milestone): array
    {
        $allDay = (bool) ($data['all_day'] ?? false);
        $start = $this->date($data['starts_at']);
        $end = isset($data['ends_at']) ? $this->date($data['ends_at']) : null;

        if ($milestone) {
            $start = $allDay ? $start->startOfDay() : $start;

            return ['starts_at' => $start, 'ends_at' => $start, 'all_day' => $allDay];
        }

        if ($allDay) {
            $start = $start->startOfDay();
            $end = ($end ?? $start)->endOfDay();
        } else {
            $end ??= $start->addHour();
        }

        if ($end->lt($start)) {
            throw ValidationException::withMessages(['ends_at' => 'Koniec zadania nie może być przed jego początkiem.']);
        }

        return ['starts_at' => $start, 'ends_at' => $end, 'all_day' => $allDay];
    }

    private function date(mixed $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value)->setTimezone(config('app.timezone'));
    }

    /**
     * The task this one waits for: one the person sees, never the task itself or one that depends on it.
     *
     * @param  array{kind: string, type: ?HeadquarterType, headquarter: ?int, branch: ?int}  $context
     */
    private function dependency(User $actor, array $context, mixed $dependsOnId, ?int $taskId): ?int
    {
        if ($dependsOnId === null || $dependsOnId === '') {
            return null;
        }

        $dependsOnId = (int) $dependsOnId;
        $visible = $this->findTask($actor, $context, $dependsOnId);

        if ($visible === null || $dependsOnId === $taskId) {
            throw ValidationException::withMessages(['depends_on_id' => 'Wybierz inne zadanie, od którego to zależy.']);
        }

        if ($taskId !== null && $this->tasks->dependsOnChain((int) $actor->organization_id, $dependsOnId, $taskId)) {
            throw ValidationException::withMessages(['depends_on_id' => 'To zadanie zależy już od wybranego (zależność kołowa).']);
        }

        return $dependsOnId;
    }

    /**
     * The rows of task_assignments for the requested targets, checked against what the person may assign to.
     *
     * @param  list<array<string, mixed>>  $requested  [{type: organization|headquarters|branch|user, unit_type?: shops|warehouses, id?: int}]
     * @return list<array<string, mixed>>
     */
    private function assignments(User $actor, array $requested): array
    {
        $targets = $this->targets($actor);
        $rows = [];

        foreach ($requested as $item) {
            $type = $item['type'] ?? null;
            $kind = HeadquarterType::tryFrom((string) ($item['unit_type'] ?? ''));
            $id = isset($item['id']) ? (int) $item['id'] : null;

            $row = match (true) {
                $type === 'organization' && $targets['organization'] => ['assignee_type' => TaskAssignment::ORGANIZATION],
                $type === 'headquarters' && $kind !== null && $this->allowed($targets['headquarters'], $kind, $id) => ['assignee_type' => TaskAssignment::HEADQUARTERS, $kind->headquarterColumn() => $id],
                $type === 'branch' && $kind !== null && $this->allowed($targets['branches'], $kind, $id) => ['assignee_type' => TaskAssignment::BRANCH, $kind->branchColumn() => $id],
                $type === 'user' && $id !== null && collect($targets['people'])->contains('id', $id) => ['assignee_type' => TaskAssignment::USER, 'user_id' => $id],
                default => null,
            };

            if ($row === null) {
                throw ValidationException::withMessages(['assignments' => 'Nie możesz przydzielić zadania do wybranego adresata.']);
            }

            $rows[json_encode($row)] = $row;
        }

        if ($rows === []) {
            throw ValidationException::withMessages(['assignments' => 'Wybierz, dla kogo jest zadanie.']);
        }

        return array_values($rows);
    }

    /**
     * @param  list<array<string, mixed>>  $units
     */
    private function allowed(array $units, HeadquarterType $kind, ?int $id): bool
    {
        return $id !== null && collect($units)->contains(fn (array $unit) => $unit['type'] === $kind->value && $unit['id'] === $id);
    }
}
