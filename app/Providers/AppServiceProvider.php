<?php

namespace App\Providers;

use App\Repositories\Contracts\ApiKeyRepositoryInterface;
use App\Repositories\Contracts\SyncedDataRepositoryInterface;
use App\Repositories\Contracts\BranchRepositoryInterface;
use App\Repositories\Contracts\HeadquarterManagerRepositoryInterface;
use App\Repositories\Contracts\HeadquarterRepositoryInterface;
use App\Repositories\Contracts\NipRegistryRepositoryInterface;
use App\Repositories\Contracts\OrganizationInvitationRepositoryInterface;
use App\Repositories\Contracts\OrganizationMemberRepositoryInterface;
use App\Repositories\Contracts\ProductRepositoryInterface;
use App\Repositories\Contracts\TaskRepositoryInterface;
use App\Repositories\Contracts\UnitMembershipRepositoryInterface;
use App\Repositories\EloquentHeadquarterManagerRepository;
use App\Repositories\EloquentOrganizationInvitationRepository;
use App\Repositories\EloquentApiKeyRepository;
use App\Repositories\EloquentSyncedDataRepository;
use App\Repositories\EloquentProductRepository;
use App\Repositories\EloquentTaskRepository;
use App\Repositories\EloquentUnitMembershipRepository;
use App\Services\Contracts\TaskServiceInterface;
use App\Services\ApiKeyService;
use App\Services\SyncService;
use App\Services\ProductService;
use App\Services\TaskService;
use App\Services\Contracts\UnitMembershipServiceInterface;
use App\Services\UnitMembershipService;
use App\Services\Contracts\HeadquarterManagerServiceInterface;
use App\Services\HeadquarterManagerService;
use App\Repositories\MfNipRegistryRepository;
use App\Services\Contracts\NipVerificationServiceInterface;
use App\Services\NipVerificationService;
use App\Repositories\EloquentOrganizationMemberRepository;
use App\Services\Contracts\OrganizationMembersServiceInterface;
use App\Services\OrganizationMembersService;
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
use App\Services\Contracts\ApiKeyServiceInterface;
use App\Services\Contracts\SyncServiceInterface;
use App\Services\Contracts\ProductServiceInterface;
use App\Services\Contracts\StructureListingServiceInterface;
use App\Services\Contracts\TeamMembersServiceInterface;
use App\Services\HeadquarterInvitationService;
use App\Services\HeadquarterService;
use App\Services\NipService;
use App\Services\StructureListingService;
use App\Services\TeamMembersService;
use App\Support\TrustedUrl;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\DevCommands;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\RateLimiter;
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
            $app->make(HeadquarterServiceInterface::class),
        ));
        $this->app->bind(HeadquarterService::class, fn ($app) => new HeadquarterService(
            $app->make(ShopHeadquarterRepository::class),
            $app->make(WarehouseHeadquarterRepository::class),
            $app->make(NipServiceInterface::class),
            $app->make(OrganizationInvitationRepositoryInterface::class),
        ));

        $this->app->bind(OrganizationMemberRepositoryInterface::class, EloquentOrganizationMemberRepository::class);
        $this->app->bind(OrganizationMembersServiceInterface::class, OrganizationMembersService::class);
        $this->app->bind(NipRegistryRepositoryInterface::class, MfNipRegistryRepository::class);
        $this->app->bind(NipVerificationServiceInterface::class, NipVerificationService::class);
        $this->app->bind(HeadquarterManagerRepositoryInterface::class, EloquentHeadquarterManagerRepository::class);
        $this->app->bind(HeadquarterManagerServiceInterface::class, HeadquarterManagerService::class);
        $this->app->bind(UnitMembershipRepositoryInterface::class, EloquentUnitMembershipRepository::class);
        $this->app->bind(UnitMembershipServiceInterface::class, UnitMembershipService::class);
        $this->app->bind(TaskRepositoryInterface::class, EloquentTaskRepository::class);
        $this->app->bind(TaskServiceInterface::class, TaskService::class);
        $this->app->bind(ProductRepositoryInterface::class, EloquentProductRepository::class);
        $this->app->bind(ProductServiceInterface::class, ProductService::class);
        $this->app->bind(ApiKeyRepositoryInterface::class, EloquentApiKeyRepository::class);
        $this->app->bind(ApiKeyServiceInterface::class, ApiKeyService::class);
        $this->app->bind(SyncedDataRepositoryInterface::class, EloquentSyncedDataRepository::class);
        $this->app->bind(SyncServiceInterface::class, SyncService::class);
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

        // overrides the vendor's "reverb" entry of `composer run dev` (same name, userland wins) to add --debug
        DevCommands::artisan('reverb:start --debug', 'reverb');
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

        // generated links (e-mails) point at APP_URL in production, not at whatever Host header the request came with
        TrustedUrl::pin(config('app.url'), app()->isProduction());

        // connectors of units: the limit is per key (a request is let in only after its key is known), not per address
        RateLimiter::for('connector', fn (Request $request) => Limit::perMinute(120)->by('connector:'.($request->attributes->get('api_key')?->id ?? $request->ip())));

        // outside production a field missing from $fillable is an error, not silently dropped
        Model::preventSilentlyDiscardingAttributes(! app()->isProduction());

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
