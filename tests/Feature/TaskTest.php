<?php

use App\Enums\UserRole;
use App\Http\Controllers\TaskController;
use App\Models\Organization;
use App\Models\ShopBranch;
use App\Models\ShopHeadquarter;
use App\Models\Task;
use App\Models\User;
use App\Models\WarehouseBranch;
use App\Models\WarehouseHeadquarter;
use App\Repositories\Contracts\TaskRepositoryInterface;
use App\Repositories\EloquentTaskRepository;
use App\Services\Contracts\TaskServiceInterface;
use App\Services\TaskService;

/**
 * Organization with an owner, two shop headquarters (A with two branches, B with one), a warehouse headquarters
 * with a branch, their people, and a second organization.
 *
 * @return array<string, mixed>
 */
function taskFixture(): array
{
    $org = Organization::create(['name' => 'Org']);
    $other = Organization::create(['name' => 'Inna']);

    $hqA = ShopHeadquarter::create(['organization_id' => $org->id, 'name' => 'Sklep A']);
    $hqB = ShopHeadquarter::create(['organization_id' => $org->id, 'name' => 'Sklep B']);
    $a1 = ShopBranch::create(['organization_id' => $org->id, 'shop_headquarter_id' => $hqA->id, 'name' => 'Placówka A1']);
    $a2 = ShopBranch::create(['organization_id' => $org->id, 'shop_headquarter_id' => $hqA->id, 'name' => 'Placówka A2']);
    $b1 = ShopBranch::create(['organization_id' => $org->id, 'shop_headquarter_id' => $hqB->id, 'name' => 'Placówka B1']);
    $hqW = WarehouseHeadquarter::create(['organization_id' => $org->id, 'name' => 'Hurtownia']);
    $w1 = WarehouseBranch::create(['organization_id' => $org->id, 'warehouse_headquarter_id' => $hqW->id, 'name' => 'Magazyn 1']);

    $person = fn (UserRole $role, string $name, array $units = [], ?int $orgId = null) => User::factory()->create(['role' => $role, 'name' => $name, 'organization_id' => $orgId ?? $org->id, ...$units]);

    $otherHq = ShopHeadquarter::create(['organization_id' => $other->id, 'name' => 'Obca']);

    return [
        'org' => $org, 'hqA' => $hqA, 'hqB' => $hqB, 'a1' => $a1, 'a2' => $a2, 'b1' => $b1, 'hqW' => $hqW, 'w1' => $w1,
        'owner' => $person(UserRole::ORGANIZATION_OWNER, 'Właściciel'),
        'mA' => $person(UserRole::SHOP_OWNER, 'Kierownik A', ['shop_headquarter_id' => $hqA->id]),
        'mB' => $person(UserRole::SHOP_OWNER, 'Kierownik B', ['shop_headquarter_id' => $hqB->id]),
        'lA1' => $person(UserRole::SHOP_LEADER, 'Lider A1', ['shop_headquarter_id' => $hqA->id, 'shop_branch_id' => $a1->id]),
        'wA1' => $person(UserRole::SHOP_WORKER, 'Pracownik A1', ['shop_headquarter_id' => $hqA->id, 'shop_branch_id' => $a1->id]),
        'lA2' => $person(UserRole::SHOP_LEADER, 'Lider A2', ['shop_headquarter_id' => $hqA->id, 'shop_branch_id' => $a2->id]),
        'wA2' => $person(UserRole::SHOP_WORKER, 'Pracownik A2', ['shop_headquarter_id' => $hqA->id, 'shop_branch_id' => $a2->id]),
        'lB1' => $person(UserRole::SHOP_LEADER, 'Lider B1', ['shop_headquarter_id' => $hqB->id, 'shop_branch_id' => $b1->id]),
        'mW' => $person(UserRole::WAREHOUSE_OWNER, 'Kierownik hurtowni', ['warehouse_headquarter_id' => $hqW->id]),
        'wW1' => $person(UserRole::WAREHOUSE_WORKER, 'Magazynier', ['warehouse_headquarter_id' => $hqW->id, 'warehouse_branch_id' => $w1->id]),
        'admin' => User::factory()->create(['role' => UserRole::Admin, 'name' => 'Admin']),
        'foreign' => $person(UserRole::SHOP_OWNER, 'Obcy', ['shop_headquarter_id' => $otherHq->id], $other->id),
        'foreignOwner' => $person(UserRole::ORGANIZATION_OWNER, 'Obcy właściciel', [], $other->id),
    ];
}

/** A task payload for the API. */
function taskPayload(array $assignments, array $override = []): array
{
    return [
        'title' => 'Inwentaryzacja',
        'priority' => 'high',
        'starts_at' => '2026-11-02 09:00:00',
        'ends_at' => '2026-11-04 17:00:00',
        'assignments' => $assignments,
        ...$override,
    ];
}

$unit = fn (string $type, int $id, string $unitType = 'shops') => ['type' => $type, 'unit_type' => $unitType, 'id' => $id];

dataset('rolesWithTasks', [
    'administrator' => ['admin', 'platform/admin/tasks/index', '/platform/admin/tasks'],
    'właściciel organizacji' => ['owner', 'platform/organization/tasks/index', '/platform/organization/tasks'],
    'kierownik siedziby sklepu' => ['mA', 'platform/shop/owner/tasks/index', '/shop/owner/tasks'],
    'kierownik placówki sklepu' => ['lA1', 'platform/shop/leader/tasks/index', '/shop/leader/tasks'],
    'pracownik sklepu' => ['wA1', 'platform/shop/worker/tasks/index', '/shop/worker/tasks'],
    'kierownik siedziby hurtowni' => ['mW', 'platform/warehouses/owner/tasks/index', '/warehouse/owner/tasks'],
    'pracownik hurtowni' => ['wW1', 'platform/warehouses/worker/tasks/index', '/warehouse/worker/tasks'],
]);

test('każda rola ma widok zadań w swoim folderze, pod adresem swojego panelu', function (string $who, string $component, string $url) {
    $f = taskFixture();

    $this->actingAs($f[$who])->get($url)->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component($component)
            ->has('tasks')->has('targets')->has('priorities', 4)->has('statuses', 3)->has('can_create'));

    expect(file_exists(resource_path('js/pages/'.$component.'.tsx')))->toBeTrue();
})->with('rolesWithTasks');

test('zadania innej roli są zamknięte, a gość idzie na logowanie', function () {
    $f = taskFixture();

    $this->actingAs($f['wA1'])->get('/platform/admin/tasks')->assertForbidden();
    $this->get('/warehouse/owner/tasks')->assertForbidden();

    $this->app['auth']->forgetGuards();
    $this->get('/shop/worker/tasks')->assertRedirect(route('login'));
    $this->getJson(route('tasks.data'))->assertUnauthorized();
});

test('zadania dodają tylko właściciel organizacji, kierownik siedziby i kierownik placówki', function () {
    $f = taskFixture();

    $this->actingAs($f['owner'])->postJson(route('tasks.store'), taskPayload([['type' => 'organization']]))->assertCreated()
        ->assertJsonPath('title', 'Inwentaryzacja')
        ->assertJsonPath('priority', 'high')
        ->assertJsonPath('priority_label', 'Wysoki')
        ->assertJsonPath('assignees.0.label', 'Cała organizacja')
        ->assertJsonPath('can_edit', true);
    $this->actingAs($f['mA'])->postJson(route('tasks.store'), taskPayload([['type' => 'headquarters', 'unit_type' => 'shops', 'id' => $f['hqA']->id]]))->assertCreated();
    $this->actingAs($f['lA1'])->postJson(route('tasks.store'), taskPayload([['type' => 'branch', 'unit_type' => 'shops', 'id' => $f['a1']->id]]))->assertCreated();

    foreach (['wA1', 'wW1', 'admin'] as $who) {
        $this->actingAs($f[$who])->postJson(route('tasks.store'), taskPayload([['type' => 'user', 'id' => $f['wA1']->id]]))->assertForbidden();
    }

    expect(Task::count())->toBe(3)
        ->and(Task::first()->organization_id)->toBe($f['org']->id)
        ->and(Task::first()->created_by)->toBe($f['owner']->id);
});

test('adresatów zadania ogranicza hierarchia', function () {
    $f = taskFixture();
    $assign = fn (User $who, array $assignments) => $this->actingAs($who)->postJson(route('tasks.store'), taskPayload($assignments));

    // the owner: anything in the organization (also several targets and people), nothing outside it
    $assign($f['owner'], [
        ['type' => 'headquarters', 'unit_type' => 'warehouses', 'id' => $f['hqW']->id],
        ['type' => 'branch', 'unit_type' => 'shops', 'id' => $f['b1']->id],
        ['type' => 'user', 'id' => $f['wA1']->id],
        ['type' => 'user', 'id' => $f['wW1']->id],
    ])->assertCreated()->assertJsonCount(4, 'assignees');
    $assign($f['owner'], [['type' => 'user', 'id' => $f['foreign']->id]])->assertUnprocessable()->assertJsonValidationErrors('assignments');

    // a headquarters manager: his headquarters, its branches and people; not another headquarters, not the organization
    $assign($f['mA'], [['type' => 'branch', 'unit_type' => 'shops', 'id' => $f['a2']->id], ['type' => 'user', 'id' => $f['wA2']->id]])->assertCreated();
    foreach ([
        [['type' => 'organization']],
        [['type' => 'headquarters', 'unit_type' => 'shops', 'id' => $f['hqB']->id]],
        [['type' => 'branch', 'unit_type' => 'shops', 'id' => $f['b1']->id]],
        [['type' => 'user', 'id' => $f['lB1']->id]],
        [['type' => 'headquarters', 'unit_type' => 'warehouses', 'id' => $f['hqA']->id]],
    ] as $targets) {
        $assign($f['mA'], $targets)->assertUnprocessable()->assertJsonValidationErrors('assignments');
    }

    // a branch leader: his branch and its people only
    $assign($f['lA1'], [['type' => 'user', 'id' => $f['wA1']->id], ['type' => 'branch', 'unit_type' => 'shops', 'id' => $f['a1']->id]])->assertCreated();
    foreach ([
        [['type' => 'branch', 'unit_type' => 'shops', 'id' => $f['a2']->id]],
        [['type' => 'headquarters', 'unit_type' => 'shops', 'id' => $f['hqA']->id]],
        [['type' => 'user', 'id' => $f['wA2']->id]],
        [['type' => 'organization']],
    ] as $targets) {
        $assign($f['lA1'], $targets)->assertUnprocessable();
    }

    // at least one target, and a known kind of target
    $assign($f['owner'], [])->assertUnprocessable()->assertJsonValidationErrors('assignments');
    $assign($f['owner'], [['type' => 'planet']])->assertUnprocessable();
});

test('kto co widzi: zadania idą w dół hierarchii', function () {
    $f = taskFixture();
    $create = fn (User $who, string $title, array $assignments) => $this->actingAs($who)->postJson(route('tasks.store'), taskPayload($assignments, ['title' => $title]))->assertCreated();
    $titles = fn (User $who) => collect($this->actingAs($who)->getJson(route('tasks.data'))->assertOk()->json())->pluck('title')->sort()->values()->all();

    $create($f['owner'], 'Dla wszystkich', [['type' => 'organization']]);
    $create($f['owner'], 'Dla siedziby A', [['type' => 'headquarters', 'unit_type' => 'shops', 'id' => $f['hqA']->id]]);
    $create($f['owner'], 'Dla siedziby B', [['type' => 'headquarters', 'unit_type' => 'shops', 'id' => $f['hqB']->id]]);
    $create($f['owner'], 'Dla placówki A1', [['type' => 'branch', 'unit_type' => 'shops', 'id' => $f['a1']->id]]);
    $create($f['owner'], 'Dla pracownika A2', [['type' => 'user', 'id' => $f['wA2']->id]]);
    $create($f['owner'], 'Dla hurtowni', [['type' => 'headquarters', 'unit_type' => 'warehouses', 'id' => $f['hqW']->id]]);
    $create($f['foreignOwner'], 'Obca organizacja', [['type' => 'organization']]);

    $all = ['Dla hurtowni', 'Dla placówki A1', 'Dla pracownika A2', 'Dla siedziby A', 'Dla siedziby B', 'Dla wszystkich'];

    // the owner and the administrator: everything of the organization(s)
    expect($titles($f['owner']))->toBe($all)
        ->and($titles($f['admin']))->toBe([...$all, 'Obca organizacja'])
        ->and($titles($f['foreignOwner']))->toBe(['Obca organizacja']);

    // a headquarters manager: the organization, his headquarters, and what is below it
    expect($titles($f['mA']))->toBe(['Dla placówki A1', 'Dla pracownika A2', 'Dla siedziby A', 'Dla wszystkich'])
        ->and($titles($f['mB']))->toBe(['Dla siedziby B', 'Dla wszystkich'])
        ->and($titles($f['mW']))->toBe(['Dla hurtowni', 'Dla wszystkich']);

    // a leader: the organization, his headquarters, his branch and its people (not the other branch)
    expect($titles($f['lA1']))->toBe(['Dla placówki A1', 'Dla siedziby A', 'Dla wszystkich'])
        ->and($titles($f['lA2']))->toBe(['Dla pracownika A2', 'Dla siedziby A', 'Dla wszystkich'])
        ->and($titles($f['lB1']))->toBe(['Dla siedziby B', 'Dla wszystkich']);

    // a worker: what is for him (himself, his branch, his headquarters, everyone)
    expect($titles($f['wA1']))->toBe(['Dla placówki A1', 'Dla siedziby A', 'Dla wszystkich'])
        ->and($titles($f['wA2']))->toBe(['Dla pracownika A2', 'Dla siedziby A', 'Dla wszystkich'])
        ->and($titles($f['wW1']))->toBe(['Dla hurtowni', 'Dla wszystkich']);

    // the creator always sees the task, also when it is for people below
    $create($f['mA'], 'Moje dla A2', [['type' => 'branch', 'unit_type' => 'shops', 'id' => $f['a2']->id]]);
    expect($titles($f['mA']))->toContain('Moje dla A2')
        ->and($titles($f['lA2']))->toContain('Moje dla A2')
        ->and($titles($f['lA1']))->not->toContain('Moje dla A2')
        ->and($titles($f['mB']))->not->toContain('Moje dla A2');
});

test('zadania można filtrować okresem', function () {
    $f = taskFixture();
    $this->actingAs($f['owner']);
    $this->postJson(route('tasks.store'), taskPayload([['type' => 'organization']], ['title' => 'Listopad', 'starts_at' => '2026-11-10 08:00:00', 'ends_at' => '2026-11-11 08:00:00']));
    $this->postJson(route('tasks.store'), taskPayload([['type' => 'organization']], ['title' => 'Grudzień', 'starts_at' => '2026-12-10 08:00:00', 'ends_at' => '2026-12-11 08:00:00']));

    $titles = fn (array $query) => collect($this->getJson(route('tasks.data', $query))->assertOk()->json())->pluck('title')->all();

    expect($titles(['from' => '2026-11-01', 'to' => '2026-11-30']))->toBe(['Listopad'])
        ->and($titles(['from' => '2026-11-11 07:00:00', 'to' => '2026-12-01']))->toBe(['Listopad'])
        ->and($titles(['from' => '2026-12-01']))->toBe(['Grudzień'])
        ->and($titles([]))->toBe(['Listopad', 'Grudzień']);
    $this->getJson(route('tasks.data', ['from' => 'nie-data']))->assertUnprocessable();
});

test('termin, kamień milowy i zadanie na cały dzień są normalizowane', function () {
    $f = taskFixture();
    $this->actingAs($f['owner']);
    $store = fn (array $override) => $this->postJson(route('tasks.store'), taskPayload([['type' => 'organization']], $override));

    // a milestone is one moment
    $milestone = $store(['title' => 'Kamień', 'is_milestone' => true, 'starts_at' => '2026-11-20 12:00:00', 'ends_at' => '2026-11-25 12:00:00'])->assertCreated();
    expect($milestone->json('is_milestone'))->toBeTrue()
        ->and($milestone->json('starts_at'))->toBe($milestone->json('ends_at'));

    // an all-day task spans whole days
    $allDay = $store(['title' => 'Cały dzień', 'all_day' => true, 'starts_at' => '2026-11-03 15:30:00', 'ends_at' => '2026-11-05 03:00:00'])->assertCreated();
    expect(Task::find($allDay->json('id'))->starts_at->format('Y-m-d H:i:s'))->toBe('2026-11-03 00:00:00')
        ->and(Task::find($allDay->json('id'))->ends_at->format('Y-m-d H:i:s'))->toBe('2026-11-05 23:59:59');

    // no end: an hour; an end before the start: refused
    $oneHour = $store(['title' => 'Godzina', 'ends_at' => null, 'starts_at' => '2026-11-03 10:00:00'])->assertCreated();
    expect(Task::find($oneHour->json('id'))->ends_at->format('H:i'))->toBe('11:00');
    $store(['ends_at' => '2026-11-01 09:00:00'])->assertUnprocessable()->assertJsonValidationErrors('ends_at');

    // validation
    $store(['title' => ''])->assertUnprocessable()->assertJsonValidationErrors('title');
    $store(['priority' => 'asap'])->assertUnprocessable()->assertJsonValidationErrors('priority');
    $store(['progress' => 150])->assertUnprocessable()->assertJsonValidationErrors('progress');
});

test('twórca i właściciel zmieniają zadanie, adresaci tylko jego status i postęp', function () {
    $f = taskFixture();
    $task = $this->actingAs($f['mA'])->postJson(route('tasks.store'), taskPayload([['type' => 'branch', 'unit_type' => 'shops', 'id' => $f['a1']->id]]))->json('id');
    $url = route('tasks.update', $task);

    // the creator and the owner change everything
    $this->patchJson($url, ['title' => 'Nowy tytuł', 'priority' => 'urgent'])->assertOk()->assertJsonPath('title', 'Nowy tytuł')->assertJsonPath('priority', 'urgent');
    $this->actingAs($f['owner'])->patchJson($url, ['description' => 'Opis'])->assertOk()->assertJsonPath('can_edit', true);

    // a worker of the branch can only mark the task as done: no progress, no other status, nothing else
    $this->actingAs($f['wA1'])->patchJson($url, ['status' => 'in_progress', 'progress' => 40])->assertForbidden();
    $this->patchJson($url, ['progress' => 40])->assertForbidden();
    $this->patchJson($url, ['title' => 'Hakowanie'])->assertForbidden();
    $this->patchJson($url, ['status' => 'done', 'priority' => 'low'])->assertForbidden();
    $this->patchJson(route('tasks.schedule', $task), ['starts_at' => '2026-11-09 09:00:00'])->assertForbidden();
    $this->deleteJson(route('tasks.destroy', $task))->assertForbidden();

    // the worker finishes his part (the branch has two people): half done, and he cannot do it twice
    $this->patchJson($url, ['status' => 'done'])->assertOk()->assertJsonPath('progress', 50)->assertJsonPath('status', 'in_progress')
        ->assertJsonPath('my_done', true)->assertJsonPath('can_complete', false);
    $this->patchJson($url, ['status' => 'done'])->assertForbidden();
    expect(Task::find($task)->completed)->toBeFalse();

    // the leader of the branch does the other part: the whole task is done = 100 %, and the flag follows
    $this->actingAs($f['lA1'])->patchJson($url, ['status' => 'done'])->assertOk()->assertJsonPath('progress', 100)->assertJsonPath('status', 'done');
    expect(Task::find($task)->completed)->toBeTrue();

    // people who do not see the task do not even know it exists
    foreach (['lA2', 'wA2', 'mB', 'foreign', 'foreignOwner'] as $who) {
        $this->actingAs($f[$who])->patchJson($url, ['status' => 'done'])->assertNotFound();
        $this->deleteJson(route('tasks.destroy', $task))->assertNotFound();
    }

    // the administrator only looks
    $this->actingAs($f['admin'])->patchJson($url, ['status' => 'todo'])->assertForbidden();
    expect(Task::find($task)->title)->toBe('Nowy tytuł')->and(Task::find($task)->status->value)->toBe('done');

    // a headquarters manager cannot change the task of the owner, even when it is for his people
    $ownerTask = $this->actingAs($f['owner'])->postJson(route('tasks.store'), taskPayload([['type' => 'branch', 'unit_type' => 'shops', 'id' => $f['a1']->id]]))->json('id');
    $this->actingAs($f['mA'])->patchJson(route('tasks.update', $ownerTask), ['title' => 'Przejęte'])->assertForbidden();
});

test('przesuwanie w kalendarzu i na wykresie zmienia termin tylko twórcy i właścicielowi', function () {
    $f = taskFixture();
    $id = $this->actingAs($f['lA1'])->postJson(route('tasks.store'), taskPayload([['type' => 'user', 'id' => $f['wA1']->id]]))->json('id');
    $url = route('tasks.schedule', $id);

    $this->patchJson($url, ['starts_at' => '2026-11-09T09:00:00+01:00', 'ends_at' => '2026-11-11T17:00:00+01:00'])->assertOk();
    expect(Task::find($id)->starts_at->setTimezone('Europe/Warsaw')->format('Y-m-d H:i'))->toBe('2026-11-09 09:00');

    // moved to whole days
    $this->patchJson($url, ['starts_at' => '2026-11-16', 'ends_at' => '2026-11-18', 'all_day' => true])->assertOk()->assertJsonPath('all_day', true);
    expect(Task::find($id)->ends_at->format('Y-m-d H:i:s'))->toBe('2026-11-18 23:59:59');

    // the end before the start, no start
    $this->patchJson($url, ['starts_at' => '2026-11-20 10:00:00', 'ends_at' => '2026-11-19 10:00:00'])->assertUnprocessable();
    $this->patchJson($url, [])->assertUnprocessable()->assertJsonValidationErrors('starts_at');

    // the owner may; the assigned worker, a colleague leader and a stranger may not
    $this->actingAs($f['owner'])->patchJson($url, ['starts_at' => '2026-12-01 08:00:00', 'ends_at' => '2026-12-01 09:00:00'])->assertOk();
    $this->actingAs($f['wA1'])->patchJson($url, ['starts_at' => '2026-12-02 08:00:00'])->assertForbidden();
    $this->actingAs($f['lA2'])->patchJson($url, ['starts_at' => '2026-12-02 08:00:00'])->assertNotFound();
    $this->actingAs($f['foreignOwner'])->patchJson($url, ['starts_at' => '2026-12-02 08:00:00'])->assertNotFound();

    // a milestone stays one moment when it is moved
    $milestone = $this->actingAs($f['owner'])->postJson(route('tasks.store'), taskPayload([['type' => 'organization']], ['is_milestone' => true]))->json('id');
    $this->patchJson(route('tasks.schedule', $milestone), ['starts_at' => '2026-12-05 10:00:00', 'ends_at' => '2026-12-09 10:00:00'])->assertOk();
    expect(Task::find($milestone)->starts_at->equalTo(Task::find($milestone)->ends_at))->toBeTrue();
});

test('zależności między zadaniami: tylko widoczne, bez cykli', function () {
    $f = taskFixture();
    $this->actingAs($f['owner']);
    $first = $this->postJson(route('tasks.store'), taskPayload([['type' => 'organization']], ['title' => 'Pierwsze']))->json('id');
    $second = $this->postJson(route('tasks.store'), taskPayload([['type' => 'organization']], ['title' => 'Drugie', 'depends_on_id' => $first]))->assertCreated()
        ->assertJsonPath('depends_on_id', $first)->assertJsonPath('depends_on_title', 'Pierwsze')->json('id');

    // a cycle and itself
    $this->patchJson(route('tasks.update', $first), ['depends_on_id' => $second])->assertUnprocessable()->assertJsonValidationErrors('depends_on_id');
    $this->patchJson(route('tasks.update', $first), ['depends_on_id' => $first])->assertUnprocessable()->assertJsonValidationErrors('depends_on_id');

    // a task of another organization does not exist for the owner
    $foreign = $this->actingAs($f['foreignOwner'])->postJson(route('tasks.store'), taskPayload([['type' => 'organization']]))->json('id');
    $this->actingAs($f['owner'])->patchJson(route('tasks.update', $second), ['depends_on_id' => $foreign])->assertUnprocessable();

    // the dependency can be removed
    $this->patchJson(route('tasks.update', $second), ['depends_on_id' => null])->assertOk()->assertJsonPath('depends_on_id', null);
});

test('adresatów zadania można zmienić, a zadanie usunąć', function () {
    $f = taskFixture();
    $this->actingAs($f['owner']);
    $id = $this->postJson(route('tasks.store'), taskPayload([['type' => 'organization']]))->json('id');

    $this->patchJson(route('tasks.update', $id), ['assignments' => [['type' => 'user', 'id' => $f['wA1']->id]]])->assertOk()
        ->assertJsonCount(1, 'assignees')->assertJsonPath('assignees.0.label', 'Pracownik A1');
    $this->patchJson(route('tasks.update', $id), ['assignments' => [['type' => 'user', 'id' => $f['foreign']->id]]])->assertUnprocessable();
    expect(Task::find($id)->assignments()->count())->toBe(1);

    $this->deleteJson(route('tasks.destroy', $id))->assertNoContent();
    expect(Task::count())->toBe(0);
});

test('lista możliwych adresatów zależy od roli', function () {
    $f = taskFixture();
    $board = fn (User $who) => $this->actingAs($who)->get(match ($who->role) {
        UserRole::ORGANIZATION_OWNER => '/platform/organization/tasks',
        UserRole::SHOP_OWNER => '/shop/owner/tasks',
        UserRole::SHOP_LEADER => '/shop/leader/tasks',
        default => '/shop/worker/tasks',
    })->viewData('page')['props'];

    $owner = $board($f['owner']);
    expect($owner['can_create'])->toBeTrue()
        ->and($owner['targets']['organization'])->toBeTrue()
        ->and(collect($owner['targets']['headquarters'])->pluck('name')->all())->toBe(['Hurtownia', 'Sklep A', 'Sklep B'])
        ->and($owner['targets']['branches'])->toHaveCount(4)
        ->and($owner['targets']['people'])->toHaveCount(9);

    $manager = $board($f['mA']);
    expect($manager['targets']['organization'])->toBeFalse()
        ->and(collect($manager['targets']['headquarters'])->pluck('name')->all())->toBe(['Sklep A'])
        ->and(collect($manager['targets']['branches'])->pluck('name')->all())->toBe(['Placówka A1', 'Placówka A2'])
        ->and(collect($manager['targets']['people'])->pluck('name')->all())->toBe(['Kierownik A', 'Lider A1', 'Lider A2', 'Pracownik A1', 'Pracownik A2']);

    $leader = $board($f['lA1']);
    expect($leader['targets']['headquarters'])->toBe([])
        ->and(collect($leader['targets']['branches'])->pluck('name')->all())->toBe(['Placówka A1'])
        ->and(collect($leader['targets']['people'])->pluck('name')->all())->toBe(['Lider A1', 'Pracownik A1']);

    $worker = $board($f['wA1']);
    expect($worker['can_create'])->toBeFalse()->and($worker['targets']['people'])->toBe([]);
});

test('kontroler, serwis i repozytorium zadań są powiązane konstruktorami', function () {
    expect(app(TaskServiceInterface::class))->toBeInstanceOf(TaskService::class)
        ->and(app(TaskRepositoryInterface::class))->toBeInstanceOf(EloquentTaskRepository::class);

    $controller = new ReflectionClass(TaskController::class);
    $service = new ReflectionClass(TaskService::class);

    expect($controller->getConstructor()->getParameters()[0]->getType()->getName())->toBe(TaskServiceInterface::class)
        ->and($service->getConstructor()->getParameters()[0]->getType()->getName())->toBe(TaskRepositoryInterface::class);
});

test('izolacja organizacji: zakres wymaga organizacji, a repozytorium nie widzi cudzych zadań', function () {
    $f = taskFixture();
    $repository = app(TaskRepositoryInterface::class);

    expect(fn () => new App\Support\TaskAccessScope(0, 1))->toThrow(InvalidArgumentException::class);

    $mine = $this->actingAs($f['owner'])->postJson(route('tasks.store'), taskPayload([['type' => 'organization']]))->json('id');
    $theirs = $this->actingAs($f['foreignOwner'])->postJson(route('tasks.store'), taskPayload([['type' => 'organization']]))->json('id');

    $scope = new App\Support\TaskAccessScope($f['org']->id, $f['owner']->id, all: true);

    expect($repository->visible($scope)->pluck('id')->all())->toBe([$mine])
        ->and($repository->findVisible($scope, $theirs))->toBeNull()
        ->and($repository->findVisible($scope, $mine))->not->toBeNull()
        ->and($repository->dependsOnChain($f['org']->id, $mine, $theirs))->toBeFalse();
});

test('zwykła aktualizacja nie zmienia organizacji ani autora, a przypisanie ma jeden cel', function () {
    $f = taskFixture();
    $repository = app(TaskRepositoryInterface::class);
    $id = $this->actingAs($f['owner'])->postJson(route('tasks.store'), taskPayload([['type' => 'organization']]))->json('id');

    $repository->update(Task::find($id), ['organization_id' => $f['foreign']->organization_id, 'created_by' => $f['foreign']->id, 'title' => 'Zmiana']);
    expect(Task::find($id)->organization_id)->toBe($f['org']->id)
        ->and(Task::find($id)->created_by)->toBe($f['owner']->id)
        ->and(Task::find($id)->title)->toBe('Zmiana');

    // a user target cannot smuggle a unit target in
    $repository->update(Task::find($id), [], [['assignee_type' => 'user', 'user_id' => $f['wA1']->id, 'shop_headquarter_id' => $f['hqB']->id]]);
    $row = Task::find($id)->assignments()->first();
    expect($row->user_id)->toBe($f['wA1']->id)->and($row->shop_headquarter_id)->toBeNull();

    expect(fn () => $repository->update(Task::find($id), [], [['assignee_type' => 'user']]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $repository->update(Task::find($id), [], [['assignee_type' => 'planet']]))->toThrow(InvalidArgumentException::class);
});

test('identyfikator jednostki bez jej typu jest odrzucany', function () {
    $f = taskFixture();
    $repository = app(TaskRepositoryInterface::class);

    expect(fn () => $repository->people($f['org']->id, null, null, $f['a1']->id))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $repository->branches($f['org']->id, null, $f['hqA']->id))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $repository->headquarters($f['org']->id, null, $f['hqA']->id))->toThrow(InvalidArgumentException::class);
});

test('widoczność zadania nie daje prawa do jego zmiany (polityka)', function () {
    $f = taskFixture();
    $id = $this->actingAs($f['owner'])->postJson(route('tasks.store'), taskPayload([['type' => 'organization']]))->json('id');
    $task = Task::find($id);

    expect($f['wA1']->can('update', $task))->toBeFalse()
        ->and($f['lA1']->can('delete', $task))->toBeFalse()
        ->and($f['admin']->can('update', $task))->toBeFalse()
        ->and($f['foreignOwner']->can('update', $task))->toBeFalse()
        ->and($f['owner']->can('update', $task))->toBeTrue()
        ->and($f['mA']->can('create', Task::class))->toBeTrue()
        ->and($f['wA1']->can('create', Task::class))->toBeFalse();
});

test('kamień milowy akceptuje tylko kierownik albo właściciel, pracownik nie', function () {
    $f = taskFixture();
    $milestone = $this->actingAs($f['owner'])->postJson(route('tasks.store'), taskPayload([['type' => 'organization']], ['is_milestone' => true]))->assertCreated();
    $id = $milestone->json('id');

    // a worker sees it but cannot accept it
    $this->actingAs($f['wA1'])->getJson(route('tasks.data'))->assertOk()->assertJsonPath('0.can_complete', false);
    $this->patchJson(route('tasks.update', $id), ['status' => 'done'])->assertForbidden();

    // a branch leader (not the creator) accepts it
    $this->actingAs($f['lA1'])->getJson(route('tasks.data'))->assertJsonPath('0.can_complete', true);
    $this->patchJson(route('tasks.update', $id), ['status' => 'done'])->assertOk()->assertJsonPath('status', 'done')->assertJsonPath('can_complete', false);

    // a milestone of the branch leader, accepted by a headquarters manager
    $second = $this->actingAs($f['lA1'])->postJson(route('tasks.store'), taskPayload([['type' => 'branch', 'unit_type' => 'shops', 'id' => $f['a1']->id]], ['is_milestone' => true]))->json('id');
    $this->actingAs($f['wA1'])->patchJson(route('tasks.update', $second), ['status' => 'done'])->assertForbidden();
    $this->actingAs($f['mA'])->patchJson(route('tasks.update', $second), ['status' => 'done'])->assertOk();
});

test('zadanie dla placówki wykonują osoby w niej, a przełożeni i właściciel widzą kto skończył', function () {
    $f = taskFixture();
    $id = $this->actingAs($f['owner'])->postJson(route('tasks.store'), taskPayload([['type' => 'branch', 'unit_type' => 'shops', 'id' => $f['a1']->id]]))->json('id');
    $names = fn (array $executors) => collect($executors)->pluck('name')->sort()->values()->all();
    $task = fn (User $who) => collect($this->actingAs($who)->getJson(route('tasks.data'))->json())->firstWhere('id', $id);

    // the people of the branch carry it out; a worker sees the counts and himself, not the names
    expect($task($f['wA1']))->toMatchArray(['is_executor' => true, 'executors_total' => 2, 'executors_done' => 0, 'my_done' => false, 'executors' => []]);

    $this->actingAs($f['wA1'])->patchJson(route('tasks.update', $id), ['status' => 'done'])->assertOk()->assertJsonPath('progress', 50);

    // the owner sees everyone and who has finished, the headquarters manager and the leader the people below them
    $owner = $task($f['owner']);
    expect($owner['is_executor'])->toBeFalse()->and($owner['executors_done'])->toBe(1)
        ->and($names($owner['executors']))->toBe(['Lider A1', 'Pracownik A1'])
        ->and(collect($owner['executors'])->firstWhere('name', 'Pracownik A1')['done'])->toBeTrue();
    expect($names($task($f['mA'])['executors']))->toBe(['Lider A1', 'Pracownik A1'])
        ->and($names($task($f['lA1'])['executors']))->toBe(['Lider A1', 'Pracownik A1']);

    // a task for the whole organization: everyone of the structure, a task for one person: that person
    $all = $this->actingAs($f['owner'])->postJson(route('tasks.store'), taskPayload([['type' => 'organization']]));
    $all->assertJsonPath('executors_total', 9);
    $one = $this->postJson(route('tasks.store'), taskPayload([['type' => 'user', 'id' => $f['wA2']->id]]));
    $one->assertJsonPath('executors_total', 1);
    $this->actingAs($f['wA2'])->patchJson(route('tasks.update', $one->json('id')), ['status' => 'done'])->assertOk()->assertJsonPath('status', 'done');

    // the superior who is not among the people doing it finishes the whole task
    $this->actingAs($f['mA'])->patchJson(route('tasks.update', $id), ['status' => 'done'])->assertOk()->assertJsonPath('status', 'done');
});

test('organizacji i autora zadania nie da się podać w żądaniu ani przypisać masowo', function () {
    $f = taskFixture();
    $this->actingAs($f['owner']);

    $this->postJson(route('tasks.store'), taskPayload([['type' => 'organization']], ['organization_id' => $f['foreignOwner']->organization_id]))->assertUnprocessable()->assertJsonValidationErrors('organization_id');
    $this->postJson(route('tasks.store'), taskPayload([['type' => 'organization']], ['created_by' => $f['wA1']->id]))->assertUnprocessable()->assertJsonValidationErrors('created_by');

    $id = $this->postJson(route('tasks.store'), taskPayload([['type' => 'organization']]))->json('id');
    $this->patchJson(route('tasks.update', $id), ['organization_id' => $f['foreignOwner']->organization_id])->assertUnprocessable();
    $this->patchJson(route('tasks.update', $id), ['created_by' => $f['wA1']->id])->assertUnprocessable();

    expect(Task::find($id)->organization_id)->toBe($f['org']->id)->and(Task::find($id)->created_by)->toBe($f['owner']->id)
        ->and((new Task)->isFillable('organization_id'))->toBeFalse()->and((new Task)->isFillable('created_by'))->toBeFalse()
        ->and((new App\Models\ApiKey)->isFillable('user_id'))->toBeFalse();
});
