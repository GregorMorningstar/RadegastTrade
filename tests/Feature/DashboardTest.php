<?php

use App\Enums\UserRole;
use App\Models\User;

test('guests are redirected to the login page', function () {
    $this->get(route('dashboard', 'any-team'))->assertRedirect(route('login'));
});

test('ogólny dashboard zespołu przekierowuje na dashboard roli użytkownika', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    $this->actingAs($admin)
        ->get(route('dashboard', $admin->currentTeam->slug))
        ->assertRedirect(route('platform.admin.dashboard'));
});
