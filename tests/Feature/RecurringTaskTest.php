<?php

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\ShopBranch;
use App\Models\ShopHeadquarter;
use App\Models\Task;
use App\Models\TaskCompletion;
use App\Models\User;
use App\Repositories\Contracts\TaskRepositoryInterface;
use Carbon\CarbonImmutable;

/**
 * An organization with an owner, a shop headquarters A (branch A1 with a leader and a worker, branch A2 with a leader),
 * another shop headquarters B with its manager, and a foreign organization with its owner.
 *
 * @return array<string, mixed>
 */
function standingFixture(): array
{
    $org = Organization::create(['name' => 'Org']);
    $other = Organization::create(['name' => 'Inna']);
    $hqA = ShopHeadquarter::create(['organization_id' => $org->id, 'name' => 'Sklep A']);
    $hqB = ShopHeadquarter::create(['organization_id' => $org->id, 'name' => 'Sklep B']);
    $a1 = ShopBranch::create(['organization_id' => $org->id, 'shop_headquarter_id' => $hqA->id, 'name' => 'Placówka A1']);
    $a2 = ShopBranch::create(['organization_id' => $org->id, 'shop_headquarter_id' => $hqA->id, 'name' => 'Placówka A2']);

    $person = fn (UserRole $role, string $name, array $units = [], ?int $orgId = null) => User::factory()->create(['role' => $role, 'name' => $name, 'organization_id' => $orgId ?? $org->id, ...$units]);

    return [
        'org' => $org, 'a1' => $a1,
        'owner' => $person(UserRole::ORGANIZATION_OWNER, 'Właściciel'),
        'mB' => $person(UserRole::SHOP_OWNER, 'Kierownik B', ['shop_headquarter_id' => $hqB->id]),
        'lA1' => $person(UserRole::SHOP_LEADER, 'Lider A1', ['shop_headquarter_id' => $hqA->id, 'shop_branch_id' => $a1->id]),
        'wA1' => $person(UserRole::SHOP_WORKER, 'Pracownik A1', ['shop_headquarter_id' => $hqA->id, 'shop_branch_id' => $a1->id]),
        'lA2' => $person(UserRole::SHOP_LEADER, 'Lider A2', ['shop_headquarter_id' => $hqA->id, 'shop_branch_id' => $a2->id]),
        'foreignOwner' => $person(UserRole::ORGANIZATION_OWNER, 'Obcy właściciel', [], $other->id),
    ];
}

/** A standing task for the branch A1: every Monday, Wednesday and Friday of November 2026, 08:00-10:00. */
function standingPayload(array $f, array $recurrence = [], array $override = []): array
{
    return [
        'title' => 'Spisanie zamówień',
        'assignments' => [['type' => 'branch', 'unit_type' => 'shops', 'id' => $f['a1']->id]],
        'recurrence' => ['from' => '2026-11-02', 'to' => '2026-11-30', 'days' => [1, 3, 5], 'start_time' => '08:00', 'end_time' => '10:00', ...$recurrence],
        ...$override,
    ];
}

test('zadanie stałe: wybrane dni tygodnia w okresie, z godzinami dnia', function () {
    $f = standingFixture();

    $this->actingAs($f['owner'])->postJson(route('tasks.store'), standingPayload($f))->assertCreated()
        ->assertJsonPath('recurrence.from', '2026-11-02')->assertJsonPath('recurrence.to', '2026-11-30')->assertJsonPath('recurrence.days', [1, 3, 5])
        ->assertJsonPath('recurrence.start_time', '08:00')->assertJsonPath('recurrence.end_time', '10:00')
        ->assertJsonPath('recurrence.label', 'Pn, Śr, Pt od 02.11.2026 do 30.11.2026, godz. 08:00–10:00')
        ->assertJsonPath('all_day', false)->assertJsonPath('status', 'todo')->assertJsonPath('is_milestone', false);

    // the task's own span is the first and the last day the series falls on (the Monday 2 and the Monday 30), with the hours
    $task = Task::sole();
    expect($task->isRecurring())->toBeTrue()
        ->and($task->starts_at->format('Y-m-d H:i'))->toBe('2026-11-02 08:00')->and($task->ends_at->format('Y-m-d H:i'))->toBe('2026-11-30 10:00')
        ->and($task->recurrence_days)->toBe([1, 3, 5])->and($task->daily_start_time)->toBe('08:00:00')->and($task->barcode)->toStartWith('2501');

    // the weekdays of the series, day by day
    expect($task->occursOn(CarbonImmutable::parse('2026-11-04')))->toBeTrue()   // Wednesday
        ->and($task->occursOn(CarbonImmutable::parse('2026-11-05')))->toBeFalse()   // Thursday
        ->and($task->occursOn(CarbonImmutable::parse('2026-11-29')))->toBeFalse()   // Sunday
        ->and($task->occursOn(CarbonImmutable::parse('2026-12-02')))->toBeFalse()   // after the period
        ->and($task->occursOn(CarbonImmutable::parse('2026-10-30')))->toBeFalse();  // before it
});

test('zadanie stałe na cały miesiąc, codziennie i na cały dzień, bez godzin', function () {
    $f = standingFixture();

    $this->actingAs($f['owner'])->postJson(route('tasks.store'), standingPayload($f, ['from' => '2026-11-01', 'to' => '2026-11-30', 'days' => [1, 2, 3, 4, 5, 6, 7], 'start_time' => null, 'end_time' => null], ['title' => 'Spotkanie zespołu']))
        ->assertCreated()->assertJsonPath('all_day', true)->assertJsonPath('recurrence.start_time', null)
        ->assertJsonPath('recurrence.label', 'codziennie od 01.11.2026 do 30.11.2026, cały dzień');

    $task = Task::sole();
    expect($task->starts_at->format('Y-m-d H:i:s'))->toBe('2026-11-01 00:00:00')->and($task->ends_at->format('Y-m-d H:i:s'))->toBe('2026-11-30 23:59:59');

    // working days get their own wording
    $this->postJson(route('tasks.store'), standingPayload($f, ['days' => [1, 2, 3, 4, 5], 'start_time' => null, 'end_time' => null]))->assertCreated()
        ->assertJsonPath('recurrence.label', 'w dni robocze od 02.11.2026 do 30.11.2026, cały dzień');
});

test('zadanie stałe: błędne dane są odrzucane', function () {
    $f = standingFixture();
    $post = fn (array $recurrence, array $override = []) => $this->actingAs($f['owner'])->postJson(route('tasks.store'), standingPayload($f, $recurrence, $override));

    $post(['days' => []])->assertStatus(422)->assertJsonValidationErrors('recurrence.days');
    $post(['days' => [0, 8]])->assertStatus(422)->assertJsonValidationErrors('recurrence.days.0');
    $post(['days' => [1, 1]])->assertStatus(422)->assertJsonValidationErrors('recurrence.days.0');
    $post(['from' => '2026-11-10', 'to' => '2026-11-02'])->assertStatus(422)->assertJsonValidationErrors('recurrence.to');
    $post(['from' => '02.11.2026'])->assertStatus(422)->assertJsonValidationErrors('recurrence.from');
    $post(['start_time' => '08:00', 'end_time' => null])->assertStatus(422)->assertJsonValidationErrors('recurrence.start_time');
    $post(['start_time' => null, 'end_time' => '10:00'])->assertStatus(422)->assertJsonValidationErrors('recurrence.start_time');
    $post(['start_time' => '10:00', 'end_time' => '08:00'])->assertStatus(422)->assertJsonValidationErrors('recurrence.end_time');
    $post(['start_time' => '8', 'end_time' => '10'])->assertStatus(422)->assertJsonValidationErrors('recurrence.start_time');

    // the weekdays have to fall inside the period (only Sunday the 8th is in it, and Mondays are chosen)
    $post(['from' => '2026-11-08', 'to' => '2026-11-08', 'days' => [1]])->assertStatus(422)->assertJsonValidationErrors('recurrence.days');
    // a milestone is one moment; too long a series
    $post([], ['is_milestone' => true])->assertStatus(422)->assertJsonValidationErrors('recurrence');
    $post(['from' => '2026-01-01', 'to' => '2028-12-31'])->assertStatus(422)->assertJsonValidationErrors('recurrence.to');

    // without a recurrence and without a start nothing is created either
    $this->postJson(route('tasks.store'), ['title' => 'X', 'assignments' => [['type' => 'organization']]])->assertStatus(422)->assertJsonValidationErrors('starts_at');
    expect(Task::count())->toBe(0);

    // a person who may not add tasks may not add standing ones
    $this->actingAs($f['wA1'])->postJson(route('tasks.store'), standingPayload($f))->assertForbidden();
});

test('zadanie stałe jest w kalendarzu okresu, który je obejmuje', function () {
    $f = standingFixture();
    $this->actingAs($f['owner'])->postJson(route('tasks.store'), standingPayload($f))->assertCreated();

    $titles = fn (string $from, string $to) => collect($this->getJson(route('tasks.data', ['from' => $from, 'to' => $to]))->json())->pluck('title')->all();

    expect($titles('2026-11-10', '2026-11-12'))->toBe(['Spisanie zamówień'])
        ->and($titles('2026-12-01', '2026-12-31'))->toBe([])
        ->and($titles('2026-10-01', '2026-10-31'))->toBe([]);
});

test('zadanie stałe wykonuje się dzień po dniu: każda osoba zaznacza dzisiejszy dzień, seria się nie kończy', function () {
    $f = standingFixture();
    $this->actingAs($f['owner'])->postJson(route('tasks.store'), standingPayload($f))->assertCreated();
    $task = Task::sole();
    $done = fn () => $this->actingAs($f['wA1'])->patchJson(route('tasks.update', $task), ['status' => 'done']);

    // Wednesday 4 November: a day of the series
    $this->travelTo(CarbonImmutable::parse('2026-11-04 09:00'));
    $this->actingAs($f['wA1'])->getJson(route('tasks.data'))->assertJsonPath('0.occurs_today', true)->assertJsonPath('0.can_complete', true)
        ->assertJsonPath('0.my_done', false)->assertJsonPath('0.done_dates', [])->assertJsonPath('0.executors_total', 2);

    $done()->assertOk()->assertJsonPath('my_done', true)->assertJsonPath('done_dates', ['2026-11-04'])->assertJsonPath('executors_done', 1)
        ->assertJsonPath('status', 'todo')->assertJsonPath('can_complete', false);
    // once a day each; the leader of the branch (the other executor) still can
    $done()->assertForbidden();
    $this->actingAs($f['lA1'])->patchJson(route('tasks.update', $task), ['status' => 'done'])->assertOk()->assertJsonPath('executors_done', 2)->assertJsonPath('status', 'todo');

    // Thursday: not a day of the series
    $this->travelTo(CarbonImmutable::parse('2026-11-05 09:00'));
    $this->actingAs($f['wA1'])->getJson(route('tasks.data'))->assertJsonPath('0.occurs_today', false)->assertJsonPath('0.can_complete', false)->assertJsonPath('0.executors_done', 0)
        ->assertJsonPath('0.done_dates', ['2026-11-04']);
    $done()->assertForbidden();

    // Friday: a new day, the person can mark it again; the earlier day stays in their list
    $this->travelTo(CarbonImmutable::parse('2026-11-06 09:00'));
    $this->actingAs($f['wA1'])->getJson(route('tasks.data'))->assertJsonPath('0.can_complete', true)->assertJsonPath('0.my_done', false);
    $done()->assertOk()->assertJsonPath('done_dates', ['2026-11-04', '2026-11-06']);

    // after the period nothing can be marked
    $this->travelTo(CarbonImmutable::parse('2026-12-02 09:00'));
    $done()->assertForbidden();
    expect(TaskCompletion::count())->toBe(3)->and($task->fresh()->status->value)->toBe('todo')->and($task->fresh()->completed)->toBeFalse();
    $this->travelBack();
});

test('zadania stałego nie zakończy ani przełożenie, ani osoba spoza wykonawców', function () {
    $f = standingFixture();
    $this->actingAs($f['owner'])->postJson(route('tasks.store'), standingPayload($f))->assertCreated();
    $task = Task::sole();
    $this->travelTo(CarbonImmutable::parse('2026-11-04 09:00'));

    // the owner is not among the people who carry it out; people of other units do not see it at all
    $this->actingAs($f['owner'])->patchJson(route('tasks.update', $task), ['status' => 'done'])->assertForbidden();
    $this->actingAs($f['mB'])->patchJson(route('tasks.update', $task), ['status' => 'done'])->assertNotFound();
    $this->actingAs($f['lA2'])->patchJson(route('tasks.update', $task), ['status' => 'done'])->assertNotFound();
    expect(TaskCompletion::count())->toBe(0)->and($task->fresh()->status->value)->toBe('todo');

    // status and progress of the series are not settable by an edit either
    $this->actingAs($f['owner'])->patchJson(route('tasks.update', $task), ['title' => 'Nowy tytuł', 'status' => 'in_progress', 'progress' => 60])->assertOk()->assertJsonPath('status', 'todo')->assertJsonPath('progress', 0);

    // dragging it would break the series: the period, days and hours are changed in the form
    $this->patchJson(route('tasks.schedule', $task), ['starts_at' => '2026-11-10 09:00:00', 'ends_at' => '2026-11-10 11:00:00'])->assertStatus(422)->assertJsonValidationErrors('starts_at');
    $this->patchJson(route('tasks.update', $task), ['starts_at' => '2026-11-10 09:00:00'])->assertStatus(422)->assertJsonValidationErrors('starts_at');
    expect($task->fresh()->starts_at->format('Y-m-d H:i'))->toBe('2026-11-02 08:00');
    $this->travelBack();
});

test('zadanie stałe można zmienić i zamienić z powrotem w zwykłe', function () {
    $f = standingFixture();
    $this->actingAs($f['owner'])->postJson(route('tasks.store'), standingPayload($f))->assertCreated();
    $task = Task::sole();

    // another period, other days, no hours: the task's own span follows
    $this->patchJson(route('tasks.update', $task), ['recurrence' => ['from' => '2026-12-01', 'to' => '2026-12-31', 'days' => [2, 4], 'start_time' => null, 'end_time' => null]])
        ->assertOk()->assertJsonPath('recurrence.label', 'Wt, Cz od 01.12.2026 do 31.12.2026, cały dzień')->assertJsonPath('all_day', true);
    expect($task->fresh()->starts_at->format('Y-m-d H:i'))->toBe('2026-12-01 00:00')->and($task->fresh()->ends_at->format('Y-m-d'))->toBe('2026-12-31');

    // an invalid change leaves the series as it was
    $this->patchJson(route('tasks.update', $task), ['recurrence' => ['from' => '2026-12-01', 'to' => '2026-12-31', 'days' => []]])->assertStatus(422);
    expect($task->fresh()->recurrence_days)->toBe([2, 4]);

    // back to an ordinary task with its own dates
    $this->patchJson(route('tasks.update', $task), ['recurrence' => null, 'starts_at' => '2026-12-10 09:00:00', 'ends_at' => '2026-12-10 11:00:00', 'all_day' => false])
        ->assertOk()->assertJsonPath('recurrence', null)->assertJsonPath('occurs_today', false);
    $fresh = $task->fresh();
    expect($fresh->isRecurring())->toBeFalse()->and($fresh->recurrence_days)->toBeNull()->and($fresh->daily_start_time)->toBeNull()
        ->and($fresh->starts_at->format('Y-m-d H:i'))->toBe('2026-12-10 09:00');

    // an ordinary task can become a standing one
    $this->patchJson(route('tasks.update', $task), ['recurrence' => ['from' => '2026-12-01', 'to' => '2026-12-31', 'days' => [1], 'start_time' => '09:00', 'end_time' => '09:30']])
        ->assertOk()->assertJsonPath('recurrence.days', [1]);
});

test('zadanie stałe przestrzega izolacji, a jeden dzień jednej osoby to jeden zapis', function () {
    $f = standingFixture();
    $this->actingAs($f['owner'])->postJson(route('tasks.store'), standingPayload($f))->assertCreated();
    $task = Task::sole();

    $this->actingAs($f['foreignOwner'])->getJson(route('tasks.data'))->assertJsonCount(0);
    $this->actingAs($f['foreignOwner'])->patchJson(route('tasks.update', $task), ['recurrence' => null, 'starts_at' => '2026-12-10 09:00:00'])->assertNotFound();

    // one person, one day, one record: a repeated mark is the same record
    $repository = app(TaskRepositoryInterface::class);
    $repository->complete($task, $f['wA1']->id, '2026-11-04');
    $repository->complete($task, $f['wA1']->id, '2026-11-04');
    $repository->complete($task, $f['wA1']->id, '2026-11-06');
    expect(TaskCompletion::where('user_id', $f['wA1']->id)->count())->toBe(2);
});
