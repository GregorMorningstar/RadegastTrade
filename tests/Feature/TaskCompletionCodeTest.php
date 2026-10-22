<?php

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\ShopBranch;
use App\Models\ShopHeadquarter;
use App\Models\Task;
use App\Models\TaskCompletion;
use App\Models\User;
use App\Notifications\TaskCompletionCodeNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

/*
|--------------------------------------------------------------------------
| A task can ask the person who carries it out for a code from their e-mail before it counts as done
|--------------------------------------------------------------------------
*/

beforeEach(fn () => Notification::fake());

/**
 * An organization with an owner, a shop headquarters A (branch A1 with a leader and a worker, branch A2 with a leader), a
 * foreign organization with its owner, and the administrator.
 *
 * @return array<string, mixed>
 */
function codeFixture(): array
{
    $org = Organization::create(['name' => 'Org']);
    $other = Organization::create(['name' => 'Inna']);
    $hqA = ShopHeadquarter::create(['organization_id' => $org->id, 'name' => 'Sklep A']);
    $a1 = ShopBranch::create(['organization_id' => $org->id, 'shop_headquarter_id' => $hqA->id, 'name' => 'Placówka A1']);
    $a2 = ShopBranch::create(['organization_id' => $org->id, 'shop_headquarter_id' => $hqA->id, 'name' => 'Placówka A2']);

    $person = fn (UserRole $role, string $name, array $units = [], ?int $orgId = null) => User::factory()->create(['role' => $role, 'name' => $name, 'organization_id' => $orgId ?? $org->id, ...$units]);

    return [
        'org' => $org, 'a1' => $a1,
        'owner' => $person(UserRole::ORGANIZATION_OWNER, 'Właściciel'),
        'lA1' => $person(UserRole::SHOP_LEADER, 'Lider A1', ['shop_headquarter_id' => $hqA->id, 'shop_branch_id' => $a1->id]),
        'wA1' => $person(UserRole::SHOP_WORKER, 'Pracownik A1', ['shop_headquarter_id' => $hqA->id, 'shop_branch_id' => $a1->id]),
        'lA2' => $person(UserRole::SHOP_LEADER, 'Lider A2', ['shop_headquarter_id' => $hqA->id, 'shop_branch_id' => $a2->id]),
        'foreignOwner' => $person(UserRole::ORGANIZATION_OWNER, 'Obcy właściciel', [], $other->id),
        'admin' => User::factory()->create(['role' => UserRole::Admin, 'name' => 'Admin']),
    ];
}

/** A task for the branch A1 added by the owner. */
function codedTask(array $f, array $override = []): Task
{
    test()->actingAs($f['owner'])->postJson(route('tasks.store'), [
        'title' => 'Spisanie zamówień',
        'starts_at' => '2026-11-10 09:00:00',
        'ends_at' => '2026-11-10 10:00:00',
        'assignments' => [['type' => 'branch', 'unit_type' => 'shops', 'id' => $f['a1']->id]],
        'requires_completion_code' => true,
        ...$override,
    ])->assertCreated();

    return Task::latest('id')->first();
}

/** The code from the (faked) e-mail of the person. */
function completionCodeOf(User $user): string
{
    $code = null;
    Notification::assertSentTo($user, TaskCompletionCodeNotification::class, function (TaskCompletionCodeNotification $notification) use (&$code) {
        $code = $notification->code;

        return true;
    });

    return $code;
}

test('kod przy wykonaniu jest domyślnie wyłączony: zadanie kończy się zwykłym przyciskiem i nic nie wysyła', function () {
    $f = codeFixture();
    $this->actingAs($f['owner'])->postJson(route('tasks.store'), ['title' => 'Zwykłe', 'starts_at' => '2026-11-10 09:00:00', 'assignments' => [['type' => 'branch', 'unit_type' => 'shops', 'id' => $f['a1']->id]]])
        ->assertCreated()->assertJsonPath('requires_completion_code', false);
    $task = Task::sole();

    $this->actingAs($f['wA1'])->patchJson(route('tasks.update', $task), ['status' => 'done'])->assertOk()->assertJsonPath('my_done', true);
    expect(TaskCompletion::count())->toBe(1);
    Notification::assertNothingSent();

    // there is no code to ask for
    $this->actingAs($f['lA1'])->postJson(route('tasks.completion-code', $task))->assertStatus(422)->assertJsonValidationErrors('task');
    Notification::assertNothingSent();
});

test('zadanie z kodem: zwykły przycisk nie wystarcza, kod dostaje osoba wykonująca, a wykonanie zapisuje się po jego wpisaniu', function () {
    $f = codeFixture();
    $task = codedTask($f);
    expect($task->requires_completion_code)->toBeTrue();

    // the ordinary way to mark it as done is closed, for the person who carries it out and for the one who set it
    $this->actingAs($f['wA1'])->patchJson(route('tasks.update', $task), ['status' => 'done'])->assertStatus(422)->assertJsonValidationErrors('status');
    $this->actingAs($f['owner'])->patchJson(route('tasks.update', $task), ['status' => 'done'])->assertStatus(422)->assertJsonValidationErrors('status');
    expect(TaskCompletion::count())->toBe(0);

    // step one: the code goes to the worker (nobody else), nothing is recorded
    $this->actingAs($f['wA1'])->postJson(route('tasks.completion-code', $task))->assertStatus(202)->assertJsonPath('pending', true)->assertJsonPath('valid_minutes', 15)->assertJsonPath('title', 'Spisanie zamówień');
    $code = completionCodeOf($f['wA1']);
    expect($code)->toMatch('/^\d{6}$/');
    Notification::assertNotSentTo($f['owner'], TaskCompletionCodeNotification::class);
    Notification::assertNotSentTo($f['lA1'], TaskCompletionCodeNotification::class);
    expect(TaskCompletion::count())->toBe(0);

    // a wrong code, a malformed one and somebody else's use of the worker's code record nothing
    $wrong = $code === '000000' ? '111111' : '000000';
    $this->postJson(route('tasks.complete', $task), ['code' => $wrong])->assertStatus(422)->assertJsonValidationErrors('code');
    $this->postJson(route('tasks.complete', $task), ['code' => 'abc'])->assertStatus(422);
    $this->actingAs($f['lA1'])->postJson(route('tasks.complete', $task), ['code' => $code])->assertStatus(422)->assertJsonValidationErrors('code');
    expect(TaskCompletion::count())->toBe(0);

    // step two: the right code records the part of the worker
    $this->actingAs($f['wA1'])->postJson(route('tasks.complete', $task), ['code' => $code])->assertOk()
        ->assertJsonPath('my_done', true)->assertJsonPath('executors_done', 1)->assertJsonPath('status', 'in_progress')->assertJsonPath('can_complete', false);
    expect(TaskCompletion::where('user_id', $f['wA1']->id)->count())->toBe(1);

    // it is single use, and a part already done cannot be asked for again
    $this->postJson(route('tasks.complete', $task), ['code' => $code])->assertForbidden();
    $this->postJson(route('tasks.completion-code', $task))->assertForbidden();

    // when the leader does his part with his code too, the task is done
    $this->actingAs($f['lA1'])->postJson(route('tasks.completion-code', $task))->assertStatus(202);
    $this->postJson(route('tasks.complete', $task), ['code' => completionCodeOf($f['lA1'])])->assertOk()->assertJsonPath('status', 'done')->assertJsonPath('executors_done', 2);
});

test('kod wykonania: limit prób, wygasanie, nowy kod zastępuje stary i kod jest na jeden dzień', function () {
    $f = codeFixture();
    $task = codedTask($f);
    $this->actingAs($f['wA1']);
    $ask = function () use ($task, $f) {
        $this->postJson(route('tasks.completion-code', $task))->assertStatus(202);

        return completionCodeOf($f['wA1']);
    };

    // five wrong codes drop it, and the right one is then useless
    $code = $ask();
    $wrong = $code === '000000' ? '111111' : '000000';

    foreach (range(1, 5) as $i) {
        $this->postJson(route('tasks.complete', $task), ['code' => $wrong])->assertStatus(422);
    }

    $this->postJson(route('tasks.complete', $task), ['code' => $code])->assertStatus(422)->assertJsonValidationErrors('code');
    expect(TaskCompletion::count())->toBe(0);

    // after 15 minutes
    $code = $ask();
    $this->travel(16)->minutes();
    $this->postJson(route('tasks.complete', $task), ['code' => $code])->assertStatus(422)->assertJsonValidationErrors('code');
    $this->travelBack();

    // a new request replaces the old code
    $first = $ask();
    $second = $ask();
    $this->postJson(route('tasks.complete', $task), ['code' => $first === $second ? $wrong : $first])->assertStatus(422);
    expect(TaskCompletion::count())->toBe(0);
    $this->postJson(route('tasks.complete', $task), ['code' => $second])->assertOk();
    expect(TaskCompletion::count())->toBe(1);

    // a code asked for before midnight does not work after it (a day is a day)
    $other = codedTask($f, ['title' => 'Inne']);
    $this->travelTo(CarbonImmutable::parse('2026-11-10 23:55'));
    $this->postJson(route('tasks.completion-code', $other))->assertStatus(202);
    $late = completionCodeOf($f['wA1']);
    $this->travelTo(CarbonImmutable::parse('2026-11-11 00:05'));
    $this->postJson(route('tasks.complete', $other), ['code' => $late])->assertStatus(422)->assertJsonValidationErrors('code');
    $this->travelBack();
});

test('kod wykonania nie obchodzi uprawnień: kto nie widzi, nie jest wykonawcą lub ma kamień milowy, kodu nie dostanie', function () {
    $f = codeFixture();
    $task = codedTask($f);

    // another branch, another organization: the task does not exist for them; the administrator only looks
    $this->actingAs($f['lA2'])->postJson(route('tasks.completion-code', $task))->assertNotFound();
    $this->actingAs($f['foreignOwner'])->postJson(route('tasks.completion-code', $task))->assertNotFound();
    $this->actingAs($f['admin'])->postJson(route('tasks.completion-code', $task))->assertForbidden();
    $this->actingAs($f['lA2'])->postJson(route('tasks.complete', $task), ['code' => '123456'])->assertNotFound();
    auth()->logout();
    $this->postJson(route('tasks.completion-code', $task))->assertUnauthorized();
    Notification::assertNothingSent();

    // the owner, who is not among the people who carry it out, may finish the whole task - with his own code
    $this->actingAs($f['owner'])->postJson(route('tasks.completion-code', $task))->assertStatus(202);
    $this->postJson(route('tasks.complete', $task), ['code' => completionCodeOf($f['owner'])])->assertOk()->assertJsonPath('status', 'done');

    // a milestone is accepted by a leader or the owner, never by a worker
    $milestone = codedTask($f, ['title' => 'Etap', 'is_milestone' => true, 'ends_at' => null]);
    $this->actingAs($f['wA1'])->postJson(route('tasks.completion-code', $milestone))->assertForbidden();
    $this->actingAs($f['lA1'])->postJson(route('tasks.completion-code', $milestone))->assertStatus(202);
    $this->postJson(route('tasks.complete', $milestone), ['code' => completionCodeOf($f['lA1'])])->assertOk()->assertJsonPath('status', 'done');
});

test('zadanie stałe z kodem: kod za każdy dzień osobno, tylko w dniu serii', function () {
    $f = codeFixture();
    $this->actingAs($f['owner'])->postJson(route('tasks.store'), [
        'title' => 'Codzienne spisanie',
        'assignments' => [['type' => 'branch', 'unit_type' => 'shops', 'id' => $f['a1']->id]],
        'recurrence' => ['from' => '2026-11-02', 'to' => '2026-11-30', 'days' => [1, 3, 5], 'start_time' => null, 'end_time' => null],
        'requires_completion_code' => true,
    ])->assertCreated()->assertJsonPath('requires_completion_code', true);
    $task = Task::sole();
    $this->actingAs($f['wA1']);

    // Wednesday: a day of the series
    $this->travelTo(CarbonImmutable::parse('2026-11-04 09:00'));
    $this->patchJson(route('tasks.update', $task), ['status' => 'done'])->assertStatus(422);
    $this->postJson(route('tasks.completion-code', $task))->assertStatus(202);
    $this->postJson(route('tasks.complete', $task), ['code' => completionCodeOf($f['wA1'])])->assertOk()->assertJsonPath('done_dates', ['2026-11-04'])->assertJsonPath('status', 'todo');
    $this->postJson(route('tasks.completion-code', $task))->assertForbidden();

    // Thursday: not a day of the series, no code
    $this->travelTo(CarbonImmutable::parse('2026-11-05 09:00'));
    $this->postJson(route('tasks.completion-code', $task))->assertForbidden();

    // Friday: a new code for a new day
    $this->travelTo(CarbonImmutable::parse('2026-11-06 09:00'));
    $this->postJson(route('tasks.completion-code', $task))->assertStatus(202);
    $this->postJson(route('tasks.complete', $task), ['code' => completionCodeOf($f['wA1'])])->assertOk()->assertJsonPath('done_dates', ['2026-11-04', '2026-11-06']);
    expect(TaskCompletion::where('user_id', $f['wA1']->id)->count())->toBe(2);
    $this->travelBack();
});

test('żądanie kodu jest ograniczone, a flagę kodu zmienia tylko ten, kto może zmieniać zadanie', function () {
    $f = codeFixture();
    $task = codedTask($f);

    // the creator (owner) can switch the code off and on; a worker cannot touch it
    $this->actingAs($f['wA1'])->patchJson(route('tasks.update', $task), ['requires_completion_code' => false])->assertForbidden();
    expect($task->fresh()->requires_completion_code)->toBeTrue();
    $this->actingAs($f['owner'])->patchJson(route('tasks.update', $task), ['requires_completion_code' => false])->assertOk()->assertJsonPath('requires_completion_code', false);
    $this->actingAs($f['wA1'])->patchJson(route('tasks.update', $task), ['status' => 'done'])->assertOk();

    // too many requests for a code in ten minutes
    $other = codedTask($f, ['title' => 'Drugie']);
    $codes = collect(range(1, 12))->map(fn () => $this->actingAs($f['lA1'])->postJson(route('tasks.completion-code', $other))->status());
    expect($codes->first())->toBe(202)->and($codes->contains(429))->toBeTrue();
});
