<?php

use App\Http\Controllers\Settings\ApiKeyController;
use App\Http\Controllers\Settings\AvatarController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Teams\TeamController;
use App\Http\Controllers\Teams\TeamInvitationController;
use App\Http\Controllers\Teams\TeamMemberController;
use App\Http\Middleware\EnsureTeamMembership;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    // GET instead of Route::redirect: the newer framework adds the QUERY method to it, which the Inertia/Wayfinder types do not know
    Route::get('settings', fn () => to_route('profile.edit'));

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::post('settings/profile/avatar', [AvatarController::class, 'update'])->middleware('throttle:10,1,s1')->name('profile.avatar.update');
    Route::post('settings/profile/avatar/confirm', [AvatarController::class, 'confirm'])->middleware('throttle:20,10,s7')->name('profile.avatar.confirm');
    Route::delete('settings/profile/avatar/pending', [AvatarController::class, 'cancel'])->name('profile.avatar.cancel');
    Route::delete('settings/profile/avatar', [AvatarController::class, 'destroy'])->name('profile.avatar.destroy');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1,s2')
        ->name('user-password.update');

    Route::inertia('settings/appearance', 'settings/appearance')->name('appearance.edit');

    // API keys of the connectors of units (who manages which unit: ApiKeyPolicy). The unit is in the URL:
    // type (shops / warehouses), level (headquarters / branches) and its id.
    Route::get('settings/api-key', [ApiKeyController::class, 'edit'])->name('api-key.edit');
    Route::prefix('settings/api-key/{type}/{level}/{unit}')
        ->whereIn('type', ['shops', 'warehouses'])
        ->whereIn('level', ['headquarters', 'branches'])
        ->whereNumber('unit')
        ->group(function () {
            Route::post('/', [ApiKeyController::class, 'store'])->middleware('throttle:10,60,s3')->name('api-key.store');
            Route::post('reveal', [ApiKeyController::class, 'reveal'])->middleware('throttle:10,60,s6')->name('api-key.reveal');
            Route::post('removal-code', [ApiKeyController::class, 'requestRemoval'])->middleware('throttle:10,60,s4')->name('api-key.removal-code');
            Route::delete('/', [ApiKeyController::class, 'destroy'])->middleware('throttle:20,60,s5')->name('api-key.destroy');
        });

    Route::get('settings/teams', [TeamController::class, 'index'])->name('teams.index');
    Route::post('settings/teams', [TeamController::class, 'store'])->name('teams.store');

    Route::middleware(EnsureTeamMembership::class)->group(function () {
        Route::get('settings/teams/{team}', [TeamController::class, 'edit'])->name('teams.edit');
        Route::patch('settings/teams/{team}', [TeamController::class, 'update'])->name('teams.update');
        Route::delete('settings/teams/{team}', [TeamController::class, 'destroy'])->name('teams.destroy');
        Route::post('settings/teams/{team}/switch', [TeamController::class, 'switch'])->name('teams.switch');
        Route::delete('settings/teams/{team}/leave', [TeamController::class, 'leave'])->name('teams.leave');

        Route::patch('settings/teams/{team}/members/{user}', [TeamMemberController::class, 'update'])->name('teams.members.update');
        Route::delete('settings/teams/{team}/members/{user}', [TeamMemberController::class, 'destroy'])->name('teams.members.destroy');

        Route::post('settings/teams/{team}/invitations', [TeamInvitationController::class, 'store'])->name('teams.invitations.store');
        Route::delete('settings/teams/{team}/invitations/{invitation}', [TeamInvitationController::class, 'destroy'])->name('teams.invitations.destroy');
    });
});
