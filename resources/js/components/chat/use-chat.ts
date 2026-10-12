import { usePage } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { chatApi } from '@/lib/chat-api';
import type { ChatContact, ChatMessage } from '@/lib/chat-api';

// Gdy WebSocket jest połączony, odświeżanie zapytaniami jest tylko zabezpieczeniem; inaczej to jedyny sposób na nowe wiadomości
const CONTACTS_INTERVAL = { realtime: 30_000, polling: 5_000 };
const MESSAGES_INTERVAL = { realtime: 20_000, polling: 2_000 };

// Jak długo od ostatniego sygnału pokazujemy „pisze…” i co ile wysyłamy własny sygnał
const TYPING_TTL = 6_000;
const TYPING_SEND_EVERY = 2_500;

function appendUnique(list: ChatMessage[], message: ChatMessage): ChatMessage[] {
    return list.some((item) => item.id === message.id) ? list : [...list, message];
}

/**
 * Stan czatu: kontakty z organizacji (z aktywnością i nieprzeczytanymi), otwarta rozmowa, wysyłanie
 * i wskaźnik „pisze…”. Kontakty odświeżają się też przy zamkniętym okienku, dzięki czemu widać
 * nieprzeczytane i aktywność.
 *
 * @param open czy okno czatu jest widoczne (wiadomości odświeżamy tylko wtedy)
 */
export function useChat(open: boolean) {
    const [contacts, setContacts] = useState<ChatContact[]>([]);
    const [unread, setUnread] = useState(0);
    const [selectedId, setSelectedId] = useState<number | null>(null);
    const [messages, setMessages] = useState<ChatMessage[]>([]);
    const [sending, setSending] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [realtime, setRealtime] = useState(false);
    const [loaded, setLoaded] = useState(false);
    const [typingAt, setTypingAt] = useState<Record<number, number>>({});
    const [, setTick] = useState(0);
    const selectedRef = useRef<number | null>(null);
    const lastTypingSent = useRef(0);
    const myId = usePage().props.auth?.user?.id;

    selectedRef.current = selectedId;

    const markTyping = useCallback((userId: number) => {
        setTypingAt((previous) => ({ ...previous, [userId]: Date.now() }));
    }, []);

    const clearTyping = useCallback((userId: number) => {
        setTypingAt((previous) => {
            if (!(userId in previous)) {
                return previous;
            }

            const rest = { ...previous };
            delete rest[userId];

            return rest;
        });
    }, []);

    const refreshContacts = useCallback(async () => {
        try {
            const result = await chatApi.contacts();

            // sesja należy już do innego konta niż to, z którym załadowano stronę: odświeżamy ją
            if (myId !== undefined && result.me !== myId) {
                window.location.reload();

                return;
            }

            setContacts(result.contacts);
            setUnread(result.unread);
            setLoaded(true);
            result.contacts.filter((contact) => contact.typing).forEach((contact) => markTyping(contact.id));
        } catch {
            // chwilowy brak połączenia: spróbujemy przy następnym odświeżeniu
        }
    }, [myId, markTyping]);

    const loadMessages = useCallback(
        async (userId: number) => {
            try {
                const result = await chatApi.messages(userId);

                if (selectedRef.current !== userId) {
                    return;
                }

                setMessages((previous) => {
                    const same =
                        previous.length === result.messages.length &&
                        previous[previous.length - 1]?.id === result.messages[result.messages.length - 1]?.id;

                    return same ? previous : result.messages;
                });

                if (result.typing) {
                    markTyping(userId);
                }

                void refreshContacts();
            } catch {
                // jw.
            }
        },
        [refreshContacts, markTyping],
    );

    useEffect(() => {
        void refreshContacts();

        const timer = window.setInterval(() => {
            if (!document.hidden) {
                void refreshContacts();
            }
        }, realtime ? CONTACTS_INTERVAL.realtime : CONTACTS_INTERVAL.polling);

        return () => window.clearInterval(timer);
    }, [refreshContacts, realtime]);

    // Wybranej osoby nie ma już na liście (np. po zmianie konta w tej samej przeglądarce): wracamy do listy
    useEffect(() => {
        if (loaded && selectedId !== null && !contacts.some((contact) => contact.id === selectedId)) {
            setSelectedId(null);
            setMessages([]);
        }
    }, [loaded, contacts, selectedId]);

    useEffect(() => {
        if (!open || selectedId === null) {
            return;
        }

        void loadMessages(selectedId);

        const timer = window.setInterval(() => {
            if (!document.hidden) {
                void loadMessages(selectedId);
            }
        }, realtime ? MESSAGES_INTERVAL.realtime : MESSAGES_INTERVAL.polling);

        return () => window.clearInterval(timer);
    }, [open, selectedId, loadMessages, realtime]);

    // Odświeżamy widok co sekundę, dopóki ktoś „pisze”, żeby wskaźnik sam zniknął po wygaśnięciu
    const hasFreshTyping = Object.values(typingAt).some((at) => Date.now() - at < TYPING_TTL);

    useEffect(() => {
        if (!hasFreshTyping) {
            return;
        }

        const timer = window.setInterval(() => setTick((value) => value + 1), 1_000);

        return () => window.clearInterval(timer);
    }, [hasFreshTyping]);

    const typingIds = Object.entries(typingAt)
        .filter(([, at]) => Date.now() - at < TYPING_TTL)
        .map(([id]) => Number(id));

    const select = useCallback((userId: number) => {
        setMessages([]);
        setError(null);
        setSelectedId(userId);
    }, []);

    const back = useCallback(() => {
        setSelectedId(null);
        setMessages([]);
    }, []);

    const send = useCallback(
        async (body: string) => {
            const text = body.trim();

            if (selectedId === null || text === '') {
                return false;
            }

            setSending(true);
            setError(null);

            try {
                const result = await chatApi.send(selectedId, text);
                setMessages((previous) => appendUnique(previous, result.message));
                lastTypingSent.current = 0;
                void refreshContacts();

                return true;
            } catch {
                setError('Nie udało się wysłać wiadomości.');

                return false;
            } finally {
                setSending(false);
            }
        },
        [selectedId, refreshContacts],
    );

    /** Wywoływane przy wpisywaniu: co kilka sekund informuje rozmówcę, że piszemy. */
    const notifyTyping = useCallback(() => {
        const now = Date.now();

        if (selectedId === null || now - lastTypingSent.current < TYPING_SEND_EVERY) {
            return;
        }

        lastTypingSent.current = now;
        chatApi.typing(selectedId).catch(() => undefined);
    }, [selectedId]);

    /** Wiadomość z Echo: dopisujemy ją do otwartej rozmowy, a w przeciwnym razie odświeżamy nieprzeczytane. */
    const receive = useCallback(
        (message: ChatMessage) => {
            const current = selectedRef.current;

            // kto wysłał wiadomość, ten już nie „pisze”
            clearTyping(message.sender_id);

            if (current !== null && (message.sender_id === current || message.recipient_id === current)) {
                setMessages((previous) => appendUnique(previous, message));
                void loadMessages(current);
            } else {
                void refreshContacts();
            }
        },
        [loadMessages, refreshContacts, clearTyping],
    );

    return {
        contacts,
        unread,
        loaded,
        selectedId,
        selected: contacts.find((contact) => contact.id === selectedId) ?? null,
        messages,
        sending,
        error,
        typingIds,
        select,
        back,
        send,
        receive,
        notifyTyping,
        markTyping,
        setRealtime,
    };
}

export type ChatState = ReturnType<typeof useChat>;
