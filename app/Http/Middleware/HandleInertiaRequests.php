<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\ColleagueInvitationService;
use App\Services\Contracts\ApiKeyServiceInterface;
use App\Services\Contracts\ChatServiceInterface;
use App\Services\Contracts\UnitMembershipServiceInterface;
use App\Services\TradeRelationshipService;
use App\Support\TradeAccess;
use App\Support\TradeBadges;
use App\Support\UnitRef;
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
     * What the trade menu shows. Only a hint for the menu: every page and action checks the rights again on the server.
     *
     * @return array{allowed: bool, offers: bool, manage: bool}
     */
    private function tradeMenu(?User $user): array
    {
        $access = app(TradeAccess::class);
        $managed = $user === null ? [] : $access->managedUnits($user);

        return [
            'allowed' => $user !== null && $access->canTrade($user),
            'offers' => collect($managed)->contains(fn (UnitRef $unit) => $unit->isWarehouse()),
            'manage' => $managed !== [],
        ];
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
            // the trade module: whether the person takes part, presents offers (manages a warehouse) and manages cooperation
            'trade' => fn () => $this->tradeMenu($user),
            // invitations waiting for an answer (cooperation, units, consents): shown in the chat and on the dashboard
            'invitations' => fn () => $user === null ? [] : [
                ...app(TradeRelationshipService::class)->inbox($user),
                ...app(ColleagueInvitationService::class)->inbox($user),
            ],
            // the bubbles of the trade menu: new orders and new offers
            'tradeBadges' => fn () => $user === null ? ['orders' => 0, 'offers' => 0] : app(TradeBadges::class)->counts($user),
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
