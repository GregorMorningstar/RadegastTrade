<?php

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\ShopBranch;
use App\Models\ShopHeadquarter;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\User;
use Illuminate\Support\Facades\Notification;

/**
 * An organization with an owner, two shop headquarters (A with branches A1 and A2, B with B1), their people, a foreign
 * organization with its owner, the administrator and two platform accounts that have no place in the structure.
 *
 * @return array<string, mixed>
 */
function searchFixture(): array
{
    $org = Organization::create(['name' => 'Org']);
    $other = Organization::create(['name' => 'Inna']);
    $hqA = ShopHeadquarter::create(['organization_id' => $org->id, 'name' => 'Sklep A']);
    $hqB = ShopHeadquarter::create(['organization_id' => $org->id, 'name' => 'Sklep B']);
    $a1 = ShopBranch::create(['organization_id' => $org->id, 'shop_headquarter_id' => $hqA->id, 'name' => 'Placówka A1']);
    $a2 = ShopBranch::create(['organization_id' => $org->id, 'shop_headquarter_id' => $hqA->id, 'name' => 'Placówka A2']);
    $b1 = ShopBranch::create(['organization_id' => $org->id, 'shop_headquarter_id' => $hqB->id, 'name' => 'Placówka B1']);

    $person = fn (UserRole $role, string $name, array $units = [], ?int $orgId = null) => User::factory()->create(['role' => $role, 'name' => $name, 'organization_id' => $orgId ?? $org->id, ...$units]);

    return [
        'org' => $org, 'other' => $other, 'hqA' => $hqA, 'hqB' => $hqB, 'a1' => $a1, 'a2' => $a2, 'b1' => $b1,
        'owner' => $person(UserRole::ORGANIZATION_OWNER, 'Właściciel'),
        'mA' => $person(UserRole::SHOP_OWNER, 'Kierownik A', ['shop_headquarter_id' => $hqA->id]),
        'mB' => $person(UserRole::SHOP_OWNER, 'Kierownik B', ['shop_headquarter_id' => $hqB->id]),
        'lA1' => $person(UserRole::SHOP_LEADER, 'Lider A1', ['shop_headquarter_id' => $hqA->id, 'shop_branch_id' => $a1->id]),
        'wA1' => $person(UserRole::SHOP_WORKER, 'Pracownik A1', ['shop_headquarter_id' => $hqA->id, 'shop_branch_id' => $a1->id]),
        'lA2' => $person(UserRole::SHOP_LEADER, 'Lider A2', ['shop_headquarter_id' => $hqA->id, 'shop_branch_id' => $a2->id]),
        'wA2' => $person(UserRole::SHOP_WORKER, 'Pracownik A2', ['shop_headquarter_id' => $hqA->id, 'shop_branch_id' => $a2->id]),
        'lB1' => $person(UserRole::SHOP_LEADER, 'Lider B1', ['shop_headquarter_id' => $hqB->id, 'shop_branch_id' => $b1->id]),
        'foreignOwner' => $person(UserRole::ORGANIZATION_OWNER, 'Obcy właściciel', [], $other->id),
        'admin' => User::factory()->create(['role' => UserRole::Admin, 'name' => 'Admin']),
        'platformManager' => User::factory()->create(['role' => UserRole::Manager, 'name' => 'Manager platformy']),
        'employee' => User::factory()->create(['role' => UserRole::Employee, 'name' => 'Pracownik bez roli']),
    ];
}

/**
 * A task straight in the database (the API is checked elsewhere): who made it and who it is for.
 *
 * @param  array<string, mixed>  $target  assignee_type and the target column
 * @param  array<string, mixed>  $attributes
 */
function searchTask(User $by, string $title, array $target, array $attributes = []): Task
{
    $task = (new Task)->forceFill([
        'organization_id' => $by->organization_id, 'created_by' => $by->id, 'title' => $title,
        'starts_at' => '2026-11-10 09:00:00', 'ends_at' => '2026-11-10 10:00:00', ...$attributes,
    ]);
    $task->save();
    TaskAssignment::create(['task_id' => $task->id, ...$target]);

    return $task->refresh();
}

/** The tasks of the fixture, by what they are for. @return array<string, Task> */
function searchTasks(array $f): array
{
    return [
        'org' => searchTask($f['owner'], 'Inwentaryzacja jesienna', ['assignee_type' => 'organization']),
        'hqA' => searchTask($f['owner'], 'Remanent siedziby A', ['assignee_type' => 'headquarters', 'shop_headquarter_id' => $f['hqA']->id]),
        'hqB' => searchTask($f['owner'], 'Remanent siedziby B', ['assignee_type' => 'headquarters', 'shop_headquarter_id' => $f['hqB']->id]),
        'a1' => searchTask($f['lA1'], 'Dostawa do A1', ['assignee_type' => 'branch', 'shop_branch_id' => $f['a1']->id]),
        'a2' => searchTask($f['lA2'], 'Dostawa do A2', ['assignee_type' => 'branch', 'shop_branch_id' => $f['a2']->id]),
        'b1' => searchTask($f['lB1'], 'Dostawa do B1', ['assignee_type' => 'branch', 'shop_branch_id' => $f['b1']->id]),
        'wA1' => searchTask($f['owner'], 'Zadanie dla pracownika A1', ['assignee_type' => 'user', 'user_id' => $f['wA1']->id]),
        // made by the leader of A1 but for the other branch: still the leader's own task
        'madeByLA1' => searchTask($f['lA1'], 'Raport lidera A1', ['assignee_type' => 'branch', 'shop_branch_id' => $f['a2']->id]),
    ];
}

/** @return list<int> the ids the person gets from the search (every page). */
function searchedIds(array $filters = []): array
{
    $ids = [];

    foreach (range(1, 20) as $page) {
        $response = test()->getJson(route('tasks.search', [...$filters, 'page' => $page, 'per_page' => 50]));
        $ids = [...$ids, ...collect($response->json('data'))->pluck('id')->all()];

        if ($page >= $response->json('last_page')) {
            break;
        }
    }

    sort($ids);

    return $ids;
}

test('wyszukiwarka znajduje dokładnie to, co dana osoba widzi na swojej liście zadań (właściciel, kierownicy, liderzy, pracownicy)', function () {
    $f = searchFixture();
    $tasks = searchTasks($f);

    foreach (['owner', 'mA', 'mB', 'lA1', 'wA1', 'lA2', 'wA2', 'lB1'] as $who) {
        $this->actingAs($f[$who]);
        $list = collect($this->getJson(route('tasks.data'))->json())->pluck('id')->sort()->values()->all();

        expect(searchedIds())->toBe($list, "wyszukiwanie ≠ lista dla: $who")->and($list)->not->toBeEmpty();
    }

    // the owner finds everything of the organization (and nothing of another one)
    $this->actingAs($f['owner']);
    expect(searchedIds())->toBe(collect($tasks)->pluck('id')->sort()->values()->all());

    // a headquarters manager: his headquarters and what is below it, not the other headquarters
    $this->actingAs($f['mA']);
    $ids = searchedIds();
    expect($ids)->toContain($tasks['hqA']->id, $tasks['a1']->id, $tasks['a2']->id, $tasks['wA1']->id, $tasks['org']->id)
        ->not->toContain($tasks['hqB']->id)->not->toContain($tasks['b1']->id);

    // a worker of a shop: what is for him, for his branch and the whole organization, nothing of the other branches
    $this->actingAs($f['wA1']);
    $ids = searchedIds();
    expect($ids)->toContain($tasks['wA1']->id, $tasks['a1']->id, $tasks['org']->id)
        ->not->toContain($tasks['a2']->id)->not->toContain($tasks['b1']->id)->not->toContain($tasks['hqB']->id)->not->toContain($tasks['madeByLA1']->id);

    // a leader: his branch, and his own task even though it is for another branch
    $this->actingAs($f['lA1']);
    $ids = searchedIds();
    expect($ids)->toContain($tasks['a1']->id, $tasks['madeByLA1']->id)->not->toContain($tasks['b1']->id)->not->toContain($tasks['hqB']->id);

    // the leader of the other branch, to whom that task is addressed, sees it too, but not the first leader's branch tasks
    $this->actingAs($f['lA2']);
    expect(searchedIds())->toContain($tasks['madeByLA1']->id, $tasks['a2']->id)->not->toContain($tasks['a1']->id);
});

test('wyszukiwarka nie pokazuje zadań obcej organizacji i nie da się jej rozszerzyć parametrami', function () {
    $f = searchFixture();
    $tasks = searchTasks($f);
    $foreign = searchTask($f['foreignOwner'], 'Zadanie obcej organizacji', ['assignee_type' => 'organization']);

    $this->actingAs($f['foreignOwner']);
    expect(searchedIds())->toBe([$foreign->id]);

    // an owner, a worker: nothing of the other organization, whatever is added to the request
    foreach (['owner', 'wA1'] as $who) {
        $this->actingAs($f[$who]);
        expect(searchedIds())->not->toContain($foreign->id)
            ->and(searchedIds(['organization_id' => $f['other']->id, 'scope' => 'all', 'user_id' => $f['owner']->id, 'all' => 1]))->not->toContain($foreign->id);
    }

    // a worker cannot reach the owner's view by asking for it
    $this->actingAs($f['wA1']);
    expect(searchedIds(['mine' => 0, 'role' => 'organization_owner']))->not->toContain($tasks['hqB']->id);
});

test('wyszukiwarka jest tylko dla ról struktury i zalogowanych; administrator widzi wszystko do odczytu', function () {
    $f = searchFixture();
    $tasks = searchTasks($f);
    $foreign = searchTask($f['foreignOwner'], 'Zadanie obcej organizacji', ['assignee_type' => 'organization']);

    // a guest, an account of the platform without a role in the structure, a person without a role at all
    auth()->logout();
    $this->getJson(route('tasks.search'))->assertUnauthorized();
    foreach (['platformManager', 'employee'] as $who) {
        $this->actingAs($f[$who])->getJson(route('tasks.search'))->assertForbidden();
    }

    // an account of an organization that lost its organization
    $orphan = User::factory()->create(['role' => UserRole::SHOP_WORKER, 'organization_id' => null]);
    $this->actingAs($orphan)->getJson(route('tasks.search'))->assertForbidden();

    // the administrator sees every organization, and can change nothing
    $this->actingAs($f['admin']);
    $response = $this->getJson(route('tasks.search', ['per_page' => 50]))->assertOk();
    expect($response->json('total'))->toBe(count($tasks) + 1)->and(collect($response->json('data'))->pluck('id'))->toContain($foreign->id)
        ->and(collect($response->json('data'))->pluck('can_edit')->unique()->all())->toBe([false]);
});

test('filtry wyszukiwarki: tekst, status, priorytet, rodzaj, moje i okres', function () {
    $f = searchFixture();
    $tasks = searchTasks($f);
    $tasks['a1']->update(['status' => 'in_progress', 'priority' => 'urgent', 'description' => 'Paleta nr 7 do rozpakowania']);
    $tasks['hqA']->update(['status' => 'done']);
    $tasks['wA1']->forceFill(['recurrence_from' => '2026-11-02', 'recurrence_to' => '2026-11-30', 'recurrence_days' => [1, 3], 'daily_start_time' => '08:00:00', 'daily_end_time' => '09:00:00'])->save();
    $tasks['a2']->update(['starts_at' => '2026-12-20 09:00:00', 'ends_at' => '2026-12-20 10:00:00']);
    $this->actingAs($f['owner']);
    $titles = fn (array $filters) => collect($this->getJson(route('tasks.search', [...$filters, 'per_page' => 50]))->json('data'))->pluck('id')->sort()->values()->all();
    $ids = fn (string ...$keys) => collect($keys)->map(fn (string $key) => $tasks[$key]->id)->sort()->values()->all();

    // the text: in the title, in the description, case-insensitively, and by the barcode of the task
    expect($titles(['q' => 'dostawa']))->toBe($ids('a1', 'a2', 'b1'))
        ->and($titles(['q' => 'paleta nr 7']))->toBe($ids('a1'))
        ->and($titles(['q' => $tasks['b1']->barcode]))->toBe($ids('b1'))
        ->and($titles(['q' => '   ']))->toHaveCount(count($tasks));

    // a "%" or "_" typed by the person is an ordinary character, not a wildcard
    $tasks['hqB']->update(['title' => 'Rabat 50% na towar_A']);
    expect($titles(['q' => '%']))->toBe($ids('hqB'))->and($titles(['q' => '_']))->toBe($ids('hqB'))->and($titles(['q' => '50%']))->toBe($ids('hqB'))
        ->and($titles(['q' => 'towar_a']))->toBe($ids('hqB'))->and($titles(['q' => 'towar_b']))->toBe([]);

    expect($titles(['status' => 'done']))->toBe($ids('hqA'))
        ->and($titles(['status' => 'in_progress']))->toBe($ids('a1'))
        ->and($titles(['priority' => 'urgent']))->toBe($ids('a1'))
        ->and($titles(['kind' => 'recurring']))->toBe($ids('wA1'))
        ->and($titles(['kind' => 'ordinary']))->toHaveCount(count($tasks) - 1)
        ->and($titles(['from' => '2026-12-01', 'to' => '2026-12-31']))->toBe($ids('a2'))
        ->and($titles(['from' => '2026-11-01', 'to' => '2026-11-30']))->not->toContain($tasks['a2']->id)
        ->and($titles(['q' => 'dostawa', 'status' => 'in_progress']))->toBe($ids('a1'));

    // "mine": made by the person or addressed to them by name
    $this->actingAs($f['lA1']);
    expect($titles(['mine' => 1]))->toBe($ids('a1', 'madeByLA1'));
    $this->actingAs($f['wA1']);
    expect($titles(['mine' => 1]))->toBe($ids('wA1'));
});

test('wyszukiwarka odrzuca błędne parametry, dzieli na strony i nie zapisuje cache', function () {
    $f = searchFixture();
    searchTasks($f);
    $this->actingAs($f['owner']);

    foreach ([['status' => 'x'], ['priority' => 'x'], ['kind' => 'x'], ['per_page' => 51], ['per_page' => 0], ['page' => 0], ['q' => str_repeat('a', 101)], ['from' => '2026-12-31', 'to' => '2026-12-01'], ['from' => 'nie data']] as $bad) {
        $this->getJson(route('tasks.search', $bad))->assertStatus(422);
    }

    $first = $this->getJson(route('tasks.search', ['per_page' => 3]))->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    expect($first->json('total'))->toBe(8)->and($first->json('last_page'))->toBe(3)->and($first->json('per_page'))->toBe(3)->and($first->json('data'))->toHaveCount(3);

    $all = collect(range(1, 3))->flatMap(fn (int $page) => $this->getJson(route('tasks.search', ['per_page' => 3, 'page' => $page]))->json('data'))->pluck('id');
    expect($all->unique())->toHaveCount(8);
    expect($this->getJson(route('tasks.search', ['per_page' => 3, 'page' => 9]))->json('data'))->toBe([]);
});
