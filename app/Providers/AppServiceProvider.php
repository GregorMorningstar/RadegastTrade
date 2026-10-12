<?php

namespace App\Providers;

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
        //
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
     * Zapisuje czas ostatniego logowania (UTC) oraz strefę czasową miejsca logowania.
     * Strefę wysyła przeglądarka w polu "timezone" formularza; nieprawidłowa wartość jest ignorowana.
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
