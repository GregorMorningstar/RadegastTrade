import { configureEcho } from '@laravel/echo-react';

/**
 * Laravel Echo (Reverb / protokół Pusher) włącza się dopiero po ustawieniu VITE_REVERB_APP_KEY.
 * Bez serwera WebSocket czat działa dalej, odświeżając się zapytaniami co kilka sekund.
 */
export const echoEnabled = Boolean(import.meta.env.VITE_REVERB_APP_KEY);

export function setupEcho(): void {
    if (!echoEnabled) {
        return;
    }

    const secure = (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https';
    const port = Number(import.meta.env.VITE_REVERB_PORT ?? (secure ? 443 : 80));

    // Ustawienia podajemy jawnie: biblioteka (wstępnie zbudowana przez Vite) nie widzi zmiennych VITE_*
    configureEcho({
        broadcaster: 'reverb',
        key: import.meta.env.VITE_REVERB_APP_KEY,
        wsHost: import.meta.env.VITE_REVERB_HOST,
        wsPort: port,
        wssPort: port,
        forceTLS: secure,
        enabledTransports: ['ws', 'wss'],
    });
}
