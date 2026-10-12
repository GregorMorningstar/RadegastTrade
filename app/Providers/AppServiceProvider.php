<?php

namespace App\Providers;

use App\Repositories\Contracts\BranchRepositoryInterface;
use App\Repositories\Contracts\HeadquarterRepositoryInterface;
use App\Repositories\Contracts\OrganizationInvitationRepositoryInterface;
use App\Repositories\EloquentOrganizationInvitationRepository;
use App\Repositories\ShopBranchRepository;
use App\Repositories\ShopHeadquarterRepository;
use App\Repositories\WarehouseBranchRepository;
use App\Repositories\WarehouseHeadquarterRepository;
use App\Services\BranchService;
use App\Services\BranchWorkerService;
use App\Services\ChatService;
use App\Services\Contracts\BranchServiceInterface;
use App\Services\Contracts\BranchWorkerServiceInterface;
use App\Services\Contracts\ChatServiceInterface;
use App\Services\Contracts\HeadquarterInvitationServiceInterface;
use App\Services\Contracts\HeadquarterServiceInterface;
use App\Services\Contracts\NipServiceInterface;
use App\Services\Contracts\StructureListingServiceInterface;
use App\Services\Contracts\TeamMembersServiceInterface;
use App\Services\HeadquarterInvitationService;
use App\Services\HeadquarterService;
use App\Services\NipService;
use App\Services\StructureListingService;
use App\Services\TeamMembersService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(OrganizationInvitationRepositoryInterface::class, EloquentOrganizationInvitationRepository::class);

        // Services depend on interfaces; the shop and the warehouse get their own repositories
        $this->app->bind(BranchService::class, fn ($app) => new BranchService(
            $app->make(ShopBranchRepository::class),
            $app->make(WarehouseBranchRepository::class),
            $app->make(OrganizationInvitationRepositoryInterface::class),
            $app->make(NipServiceInterface::class),
        ));
        $this->app->bind(HeadquarterService::class, fn ($app) => new HeadquarterService(
            $app->make(ShopHeadquarterRepository::class),
            $app->make(WarehouseHeadquarterRepository::class),
            $app->make(NipServiceInterface::class),
        ));

        $this->app->bind(BranchServiceInterface::class, BranchService::class);
        $this->app->bind(BranchWorkerServiceInterface::class, BranchWorkerService::class);
        $this->app->bind(ChatServiceInterface::class, ChatService::class);
        $this->app->bind(HeadquarterInvitationServiceInterface::class, HeadquarterInvitationService::class);
        $this->app->bind(HeadquarterServiceInterface::class, HeadquarterService::class);
        $this->app->bind(NipServiceInterface::class, NipService::class);
        $this->app->bind(StructureListingServiceInterface::class, StructureListingService::class);
        $this->app->bind(TeamMembersServiceInterface::class, TeamMembersService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->recordLastLogin();
    }

    /**
     * Stores the last login time (UTC) and the time zone of the login location.
     * The browser sends the time zone in the form's "timezone" field; an invalid value is ignored.
     */
    private function recordLastLogin(): void
    {
        Event::listen(Login::class, function (Login $event) {
            $timezone = request()->input('timezone');

            $event->user->forceFill([
                'last_login_at' => now(),
                'last_login_timezone' => is_string($timezone) && in_array($timezone, timezone_identifiers_list(), true)
                    ? $timezone
                    : null,
            ])->saveQuietly();
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
