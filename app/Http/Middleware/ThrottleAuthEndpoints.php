<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Limits for the authentication endpoints that Fortify registers without any (the password reset request, setting a new
 * password, registration with an invitation). They are keyed by the address of the caller and, where the request names
 * an account, by that account too, so neither one machine nor one mailbox can be flooded.
 */
class ThrottleAuthEndpoints
{
    /** @var array<string, list<array{0: string, 1: int, 2: int}>> route name => [[scope, attempts, decay seconds]] */
    private const LIMITS = [
        'password.email' => [['ip', 5, 60], ['email', 3, 600]],
        'password.update' => [['ip', 10, 60], ['email', 5, 600]],
        'register.store' => [['ip', 10, 60]],
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $name = $request->route()?->getName();

        if ($request->isMethod('POST') && $name !== null && isset(self::LIMITS[$name])) {
            $keys = [];

            foreach (self::LIMITS[$name] as [$scope, $attempts, $decay]) {
                $subject = $scope === 'ip' ? (string) $request->ip() : mb_strtolower(trim((string) $request->input('email')));

                // an empty address is not a key that could be shared by everybody
                if ($subject === '') {
                    continue;
                }

                $key = 'auth-throttle:'.$name.':'.$scope.':'.hash('sha256', $subject);

                if (RateLimiter::tooManyAttempts($key, $attempts)) {
                    throw new ThrottleRequestsException('Zbyt wiele prób. Spróbuj ponownie za chwilę.', null, ['Retry-After' => RateLimiter::availableIn($key)]);
                }

                $keys[$key] = $decay;
            }

            foreach ($keys as $key => $decay) {
                RateLimiter::hit($key, $decay);
            }
        }

        return $next($request);
    }
}
