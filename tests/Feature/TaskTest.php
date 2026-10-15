<?php

use App\Enums\UserRole;
use App\Http\Controllers\TaskController;
use App\Models\User;
use App\Repositories\Contracts\TaskRepositoryInterface;
use App\Repositories\EloquentTaskRepository;
use App\Services\Contracts\TaskServiceInterface;
use App\Services\TaskService;

dataset('rolesWithTasks', [
    'administrator' => [UserRole::Admin, 'platform/admin/tasks/index', '/platform/admin/tasks'],
    'właściciel organizacji' => [UserRole::ORGANIZATION_OWNER, 'platform/organization/tasks/index', '/platform/organization/tasks'],
    'kierownik siedziby sklepu' => [UserRole::SHOP_OWNER, 'platform/shop/owner/tasks/index', '/shop/owner/tasks'],
    'kierownik placówki sklepu' => [UserRole::SHOP_LEADER, 'platform/shop/leader/tasks/index', '/shop/leader/tasks'],
    'pracownik sklepu' => [UserRole::SHOP_WORKER, 'platform/shop/worker/tasks/index', '/shop/worker/tasks'],
    'kierownik siedziby hurtowni' => [UserRole::WAREHOUSE_OWNER, 'platform/warehouses/owner/tasks/index', '/warehouse/owner/tasks'],
    'kierownik placówki hurtowni' => [UserRole::WAREHOUSE_LEADER, 'platform/warehouses/leader/tasks/index', '/warehouse/leader/tasks'],
    'pracownik hurtowni' => [UserRole::WAREHOUSE_WORKER, 'platform/warehouses/worker/tasks/index', '/warehouse/worker/tasks'],
]);

test('każda rola ma widok zadań w swoim folderze, pod adresem swojego panelu', function (UserRole $role, string $component, string $url) {
    $user = User::factory()->create(['role' => $role]);

    expect($role->basePath().'/tasks')->toBe($url);

    $this->actingAs($user)->get($url)->assertOk()
        ->assertInertia(fn ($page) => $page->component($component));

    // the page file exists next to the other pages of the role
    expect(file_exists(resource_path('js/pages/'.$component.'.tsx')))->toBeTrue();
})->with('rolesWithTasks');

test('zadania innej roli są zamknięte, a gość idzie na logowanie', function () {
    $worker = User::factory()->create(['role' => UserRole::SHOP_WORKER]);

    $this->actingAs($worker)->get('/platform/admin/tasks')->assertForbidden();
    $this->get('/warehouse/owner/tasks')->assertForbidden();

    $this->app['auth']->forgetGuards();
    $this->get('/shop/worker/tasks')->assertRedirect(route('login'));
});

test('kontroler, serwis i repozytorium zadań są powiązane tylko konstruktorami', function () {
    $service = app(TaskServiceInterface::class);

    expect($service)->toBeInstanceOf(TaskService::class)
        ->and(app(TaskRepositoryInterface::class))->toBeInstanceOf(EloquentTaskRepository::class);

    // the controller needs the service, the service the repository: nothing else
    $controller = new ReflectionClass(TaskController::class);
    $service = new ReflectionClass(TaskService::class);

    expect($controller->getConstructor()->getParameters()[0]->getType()->getName())->toBe(TaskServiceInterface::class)
        ->and($service->getConstructor()->getParameters()[0]->getType()->getName())->toBe(TaskRepositoryInterface::class)
        ->and(app(TaskController::class))->toBeInstanceOf(TaskController::class);
});
