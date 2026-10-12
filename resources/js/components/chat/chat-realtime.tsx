import { useConnectionStatus, useEcho } from '@laravel/echo-react';
import { useEffect } from 'react';
import type { ChatMessage } from '@/lib/chat-api';

/**
 * Nasłuch prywatnego kanału czatu przez Laravel Echo. Renderujemy go tylko przy włączonym Echo
 * (ustawione VITE_REVERB_APP_KEY), bo hook wymaga skonfigurowanego klienta.
 */
export default function ChatRealtime({
    userId,
    onMessage,
    onTyping,
    onConnected,
}: {
    userId: number;
    onMessage: (message: ChatMessage) => void;
    /** Rozmówca pisze (sygnał z Echo) */
    onTyping: (userId: number) => void;
    /** Czy WebSocket jest połączony (wtedy czat odświeża się rzadziej) */
    onConnected: (connected: boolean) => void;
}) {
    const status = useConnectionStatus();

    useEffect(() => {
        onConnected(status === 'connected');

        return () => onConnected(false);
    }, [status, onConnected]);

    useEcho(
        `chat.${userId}`,
        '.message.sent' as never,
        (payload: unknown) => onMessage((payload as { message: ChatMessage }).message),
        [userId, onMessage],
    );

    useEcho(
        `chat.${userId}`,
        '.user.typing' as never,
        (payload: unknown) => onTyping((payload as { user_id: number }).user_id),
        [userId, onTyping],
    );

    return null;
}
