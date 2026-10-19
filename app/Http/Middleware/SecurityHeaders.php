<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Response headers that cost nothing and close common browser-side holes: no guessing of content types, no embedding of
 * the application in another site (clickjacking), no leaking of full URLs (they can carry invitation tokens) to other
 * sites, no use of the camera, microphone or location, and HTTPS only once it is known to be in use.
 *
 * A Content-Security-Policy is deliberately not set here: it has to be written against the built frontend (Vite, inline
 * data of Inertia) and checked in a browser, otherwise it breaks the pages.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        foreach ([
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
        ] as $header => $value) {
            if (! $response->headers->has($header)) {
                $response->headers->set($header, $value);
            }
        }

        // HSTS only over HTTPS in production (a browser remembers it, so a mistake on a plain-HTTP host would lock people out)
        if (app()->isProduction() && $request->isSecure() && ! $response->headers->has('Strict-Transport-Security')) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
