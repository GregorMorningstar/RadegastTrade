import { Component } from 'react';
import type { ReactNode } from 'react';

/**
 * Błąd połączenia w czasie rzeczywistym (np. niedziałający serwer WebSocket) nie może wyłączać
 * całej aplikacji: czat dalej odświeża się zapytaniami, więc po prostu nic nie renderujemy.
 */
export default class RealtimeBoundary extends Component<{ children: ReactNode }, { failed: boolean }> {
    state = { failed: false };

    static getDerivedStateFromError() {
        return { failed: true };
    }

    componentDidCatch(error: unknown) {
        console.warn('Czat w czasie rzeczywistym jest niedostępny, używam odświeżania zapytaniami.', error);
    }

    render() {
        return this.state.failed ? null : this.props.children;
    }
}
