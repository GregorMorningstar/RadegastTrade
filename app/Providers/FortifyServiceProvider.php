<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Http\Responses\LoginResponse;
use App\Http\Responses\NeutralPasswordResetLinkResponse;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use App\Http\Responses\RegisterResponse;
use App\Http\Responses\TwoFactorLoginResponse;
use App\Http\Responses\VerifyEmailResponse;
use App\Models\OrganizationInvitation;
use App\Models\TeamInvitation;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse as TwoFactorLoginResponseContract;
use Laravel\Fortify\Contracts\VerifyEmailResponse as VerifyEmailResponseContract;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(LoginResponseContract::class, LoginResponse::class);
        $this->app->singleton(RegisterResponseContract::class, RegisterResponse::class);
        $this->app->singleton(TwoFactorLoginResponseContract::class, TwoFactorLoginResponse::class);
        $this->app->singleton(VerifyEmailResponseContract::class, VerifyEmailResponse::class);

        // an unknown address gets the same answer as a known one (no way to check who has an account)
        $this->app->bind(FailedPasswordResetLinkRequestResponse::class, NeutralPasswordResetLinkResponse::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::createUsersUsing(CreateNewUser::class);
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn (Request $request) => Inertia::render('auth/login', [
            'canResetPassword' => Features::enabled(Features::resetPasswords()),
            'status' => $request->session()->get('status'),
            'teamInvitation' => $this->teamInvitation($request),
        ]));

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/reset-password', [
            'email' => $request->email,
            'token' => $request->route('token'),
        ]));

        Fortify::requestPasswordResetLinkView(fn (Request $request) => Inertia::render('auth/forgot-password', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::verifyEmailView(fn (Request $request) => Inertia::render('auth/verify-email', [
            'status' => $request->session()->get('status'),
        ]));

        // Registration is possible only with an invitation (to an organization or to a team)
        Fortify::registerView(function (Request $request) {
            $teamInvitation = $this->teamInvitation($request);
            $organizationInvitation = $this->organizationInvitation($request);

            if (! $teamInvitation && ! $organizationInvitation) {
                return redirect()->route('login')
                    ->with('status', 'Rejestracja jest możliwa tylko z zaproszenia.');
            }

            return Inertia::render('auth/register', [
                'teamInvitation' => $teamInvitation,
                'organizationInvitation' => $organizationInvitation,
            ]);
        });

        Fortify::twoFactorChallengeView(fn () => Inertia::render('auth/two-factor-challenge'));

        Fortify::confirmPasswordView(fn () => Inertia::render('auth/confirm-password'));
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            // per account and address (guessing one password), and per address alone (trying many accounts from one machine)
            return [
                Limit::perMinute(5)->by($throttleKey),
                Limit::perMinute(30)->by('login-ip:'.$request->ip()),
            ];
        });
    }

    /**
     * Get the pending team invitation context for auth pages.
     *
     * @return array{code: string, teamName: string}|null
     */
    /**
     * Active organization invitation from the link (query organization_invitation).
     *
     * @return array{code: string, email: string, phone: string|null, organizationName: string|null, resendUrl: string}|null
     */
    private function organizationInvitation(Request $request): ?array
    {
        $token = $request->query('organization_invitation');

        if (! is_string($token)) {
            return null;
        }

        $invitation = OrganizationInvitation::with('organization')->where('code', $token)->first();

        if (! $invitation?->isPending()) {
            return null;
        }

        return [
            'code' => $invitation->code,
            'email' => $invitation->email,
            'phone' => $invitation->phone,
            'organizationName' => $invitation->organization?->name,
            'resendUrl' => route('organization-invitations.resend', $invitation),
        ];
    }

    private function teamInvitation(Request $request): ?array
    {
        $invitationCode = $request->query('invitation');

        if (! is_string($invitationCode)) {
            return null;
        }

        $invitation = TeamInvitation::query()
            ->with('team')
            ->where('code', $invitationCode)
            ->where('is_active', true)
            ->whereNull('accepted_at')
            ->where(fn ($query) => $query
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>=', now()))
            ->first();

        if (! $invitation) {
            return null;
        }

        return [
            'code' => $invitation->code,
            'teamName' => $invitation->team->name,
            'email' => $invitation->email,
        ];
    }
}
