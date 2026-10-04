<?php

use App\Enums\UserRole;
use App\Models\User;

test('po zalogowaniu użytkownik trafia na dashboard swojej roli', function (UserRole $role) {
    $user = User::factory()->create(['role' => $role, 'password' => 'qwer1234']);

    $this->post('/login', ['email' => $user->email, 'password' => 'qwer1234'])
        ->assertRedirect(route($role->dashboardRoute()));

    $this->get(route($role->dashboardRoute()))->assertOk();
})->with(UserRole::cases());

test('rola bez dostępu widzi stronę z przekierowaniem na własny dashboard', function () {
    $user = User::factory()->create(['role' => UserRole::SHOP_WORKER]);

    $this->actingAs($user)
        ->get(route('platform.admin.dashboard'))
        ->assertForbidden()
        ->assertInertia(fn ($page) => $page
            ->component('errors/forbidden')
            ->where('redirectTo', route('shop.worker.dashboard')));
});

test('żądanie JSON bez uprawnień dostaje zwykłe 403', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::SHOP_WORKER]))
        ->getJson(route('platform.admin.dashboard'))
        ->assertForbidden();
});

test('nieistniejący adres daje 404 z przekierowaniem na dashboard roli', function () {
    $user = User::factory()->create(['role' => UserRole::WAREHOUSE_LEADER]);

    $this->actingAs($user)
        ->get('/nie-ma-takiej-strony')
        ->assertNotFound()
        ->assertInertia(fn ($page) => $page
            ->component('errors/not-found')
            ->where('redirectTo', route('warehouse.leader.dashboard')));
});

test('gość na nieistniejącym adresie jest kierowany na stronę główną', function () {
    $this->get('/nie-ma-takiej-strony')
        ->assertNotFound()
        ->assertInertia(fn ($page) => $page
            ->component('errors/not-found')
            ->where('redirectTo', url('/')));
});
