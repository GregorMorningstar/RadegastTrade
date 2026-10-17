<?php

namespace App\Http\Middleware;

use App\Services\Contracts\ApiKeyServiceInterface;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets a connector in with its API key (Authorization: Bearer <key>, or the X-Api-Key header). The key identifies the
 * connector's unit: the request gets it as the "api_key" attribute and everything the connector does is limited to that
 * unit's organization. Wrong keys are counted per address, so keys cannot be guessed by trying.
 */
class AuthenticateApiKey
{
    private const FAILURES_PER_MINUTE = 20;

    public function __construct(private readonly ApiKeyServiceInterface $keys)
    {
        //
    }

    public function handle(Request $request, Closure $next): Response
    {
        $failures = 'api-key-failures:'.$request->ip();

        if (RateLimiter::tooManyAttempts($failures, self::FAILURES_PER_MINUTE)) {
            return response()->json(['message' => 'Zbyt wiele nieudanych prób. Spróbuj za chwilę.'], 429, ['Retry-After' => RateLimiter::availableIn($failures)]);
        }

        $key = $request->bearerToken() ?? $request->header('X-Api-Key');
        $apiKey = is_string($key) && $key !== '' ? $this->keys->authenticate($key, $request->ip()) : null;

        if ($apiKey === null) {
            RateLimiter::hit($failures, 60);

            return response()->json(['message' => 'Nieprawidłowy lub nieaktywny klucz API.'], 401, ['WWW-Authenticate' => 'Bearer']);
        }

        $request->attributes->set('api_key', $apiKey);

        return $next($request);
    }
}
