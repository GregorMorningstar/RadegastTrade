import { useSyncExternalStore } from 'react';

/**
 * Stan okienka czatu współdzielony przez menu boczne (link „Czat”) i samo okienko w rogu ekranu.
 */
let isOpen = false;
const listeners = new Set<() => void>();

function emit(): void {
    listeners.forEach((listener) => listener());
}

export const chatWindow = {
    open(): void {
        isOpen = true;
        emit();
    },
    close(): void {
        isOpen = false;
        emit();
    },
    toggle(): void {
        isOpen = !isOpen;
        emit();
    },
};

export function useChatWindowOpen(): boolean {
    return useSyncExternalStore(
        (listener) => {
            listeners.add(listener);

            return () => listeners.delete(listener);
        },
        () => isOpen,
        () => false,
    );
}
