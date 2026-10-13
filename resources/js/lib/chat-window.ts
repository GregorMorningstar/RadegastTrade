import { useSyncExternalStore } from 'react';

/**
 * Stan okienka czatu współdzielony przez menu boczne (link „Czat”) i samo okienko w rogu ekranu.
 */
let isOpen = false;
let pendingUser: number | null = null;
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
    /** Opens the window and selects the conversation with the given user. */
    openWith(userId: number): void {
        pendingUser = userId;
        isOpen = true;
        emit();
    },
    clearPending(): void {
        pendingUser = null;
        emit();
    },
};

export function usePendingChatUser(): number | null {
    return useSyncExternalStore(
        (listener) => {
            listeners.add(listener);

            return () => listeners.delete(listener);
        },
        () => pendingUser,
        () => null,
    );
}

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
