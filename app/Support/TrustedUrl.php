<?php

namespace App\Support;

use Illuminate\Support\Facades\URL;

/**
 * Links in e-mails (password reset, invitations) are built from the host of the request, and the Host header is chosen by
 * the sender: a request for "reset my password" sent with a foreign Host would e-mail the victim a link to the attacker's
 * site, with the token in it. In production the links are therefore pinned to the configured address (APP_URL).
 */
final class TrustedUrl
{
    public static function pin(?string $appUrl, bool $production): void
    {
        $appUrl = trim((string) $appUrl);

        if (! $production || $appUrl === '' || parse_url($appUrl, PHP_URL_HOST) === null) {
            return;
        }

        URL::forceRootUrl(rtrim($appUrl, '/'));

        if (str_starts_with($appUrl, 'https://')) {
            URL::forceScheme('https');
        }
    }
}
