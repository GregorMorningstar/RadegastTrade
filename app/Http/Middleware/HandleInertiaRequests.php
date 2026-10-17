<?php

namespace App\Http\Middleware;

use App\Services\Contracts\ApiKeyServiceInterface;
use App\Services\Contracts\ChatServiceInterface;
use App\Services\Contracts\UnitMembershipServiceInterface;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user,
            ],
            // whether the person has an API key (the settings link and the button on the product list)
            'apiKeyAllowed' => $user !== null && app(ApiKeyServiceInterface::class)->canManage($user),
            // what kind of unit the person works in: the owner, a shop or a warehouse
            'unitLabel' => $user?->role->unitLabel(),
            // the headquarters and branches of the person, for the "Jednostka" section of the menu
            'units' => fn () => $user ? app(UnitMembershipServiceInterface::class)->overview($user) : [],
            'canChat' => $user !== null && app(ChatServiceInterface::class)->canUse($user),
            'rolePanel' => $user ? [
                'base' => $user->role->basePath(),
                'name' => $user->role->panelName(),
            ] : null,
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'currentTeam' => fn () => $user?->currentTeam ? $user->toUserTeam($user->currentTeam) : null,
            'teams' => fn () => $user?->toUserTeams(includeCurrent: true) ?? [],
        ];
    }
}
