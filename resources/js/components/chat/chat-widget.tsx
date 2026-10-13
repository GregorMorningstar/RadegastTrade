import { Link, usePage } from '@inertiajs/react';
import { Maximize2, MessageCircle, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import ChatBox from '@/components/chat/chat-box';
import { useChat } from '@/components/chat/use-chat';
import UserAvatar from '@/components/user-avatar';
import { chatWindow, usePendingChatUser, useChatWindowOpen } from '@/lib/chat-window';

function ChatWidgetInner() {
    const open = useChatWindowOpen();
    const chat = useChat(open);
    const [toast, setToast] = useState<{ id: number; name: string; avatar: string; body: string } | null>(null);
    const seen = useRef<number | null>(null);
    const pendingUser = usePendingChatUser();

    // Chat opened from a "write to this person" button: jump straight to that conversation
    useEffect(() => {
        if (pendingUser !== null && chat.loaded) {
            if (chat.contacts.some((contact) => contact.id === pendingUser)) {
                chat.select(pendingUser);
            }

            chatWindow.clearPending();
        }
    }, [pendingUser, chat.loaded, chat.contacts]);

    // Nowa wiadomość od kogoś, gdy nie patrzę na tę rozmowę: krótki podgląd nad przyciskiem czatu, bez odświeżania strony
    useEffect(() => {
        if (!chat.loaded) {
            return;
        }

        const incoming = chat.contacts
            .filter((contact) => contact.last_message && !contact.last_message.mine)
            .map((contact) => ({ contact, at: Date.parse(contact.last_message!.created_at) }))
            .sort((a, b) => b.at - a.at)[0];

        if (seen.current === null) {
            seen.current = incoming?.at ?? 0;

            return;
        }

        if (incoming && incoming.at > seen.current) {
            seen.current = incoming.at;

            const watching = open && chat.selectedId === incoming.contact.id;

            if (!watching && incoming.contact.unread > 0) {
                setToast({
                    id: incoming.contact.id,
                    name: incoming.contact.name,
                    avatar: incoming.contact.avatar_url,
                    body: incoming.contact.last_message!.body,
                });
            }
        }
    }, [chat.loaded, chat.contacts, chat.selectedId, open]);

    useEffect(() => {
        if (!toast) {
            return;
        }

        const timer = window.setTimeout(() => setToast(null), 7_000);

        return () => window.clearTimeout(timer);
    }, [toast]);

    const openFromToast = () => {
        if (toast) {
            chatWindow.open();
            chat.select(toast.id);
            setToast(null);
        }
    };

    return (
        <div className="fixed bottom-4 right-4 z-50 flex flex-col items-end gap-2">
            {open && (
                <section
                    aria-label="Czat"
                    className="flex h-[480px] max-h-[80vh] w-[340px] max-w-[calc(100vw-2rem)] flex-col overflow-hidden rounded-lg border border-[#dee2e6] bg-white shadow-xl"
                >
                    <header className="flex items-center justify-between bg-[#0084ff] px-3 py-2 text-white">
                        <h2 className="text-sm font-semibold">
                            Czat
                            {chat.unread > 0 && (
                                <span className="ml-2 rounded-full bg-white/90 px-1.5 py-0.5 text-[10px] font-bold text-[#0084ff]">
                                    {chat.unread}
                                </span>
                            )}
                        </h2>
                        <span className="flex items-center gap-3">
                            <Link href="/chat" title="Otwórz na całej stronie" aria-label="Otwórz czat na całej stronie">
                                <Maximize2 className="size-4" />
                            </Link>
                            <button type="button" onClick={chatWindow.close} aria-label="Zamknij czat">
                                <X className="size-4" />
                            </button>
                        </span>
                    </header>
                    <div className="min-h-0 flex-1">
                        <ChatBox chat={chat} variant="widget" />
                    </div>
                </section>
            )}

            {toast && !open && (
                <button
                    type="button"
                    onClick={openFromToast}
                    className="flex w-72 max-w-[calc(100vw-2rem)] items-center gap-3 rounded-lg border border-[#dee2e6] bg-white p-3 text-left shadow-xl"
                    aria-live="polite"
                >
                    <UserAvatar src={toast.avatar} name={toast.name} size={40} />
                    <span className="min-w-0">
                        <span className="block truncate text-sm font-semibold">{toast.name}</span>
                        <span className="block truncate text-xs text-[#6c757d]">{toast.body}</span>
                    </span>
                </button>
            )}

            <button
                type="button"
                onClick={chatWindow.toggle}
                aria-label={open ? 'Zamknij czat' : 'Otwórz czat'}
                className="relative flex size-14 items-center justify-center rounded-full bg-[#0084ff] text-white shadow-lg hover:bg-[#0073e0]"
            >
                <MessageCircle className="size-7" />
                {chat.unread > 0 && (
                    <span className="absolute -right-1 -top-1 min-w-5 rounded-full bg-[#dc3545] px-1 text-center text-xs font-bold leading-5 text-white">
                        {chat.unread}
                    </span>
                )}
            </button>
        </div>
    );
}

/**
 * Okienko czatu w rogu ekranu, jak w Messengerze lub WhatsAppie: lista aktywnych osób z organizacji i rozmowa.
 * Widoczne w każdym panelu dla kont z organizacją; na stronie /chat jest pełny widok, więc okienka nie ma.
 */
export default function ChatWidget() {
    const { props, url } = usePage();

    if (!props.canChat || url.split('?')[0] === '/chat') {
        return null;
    }

    return <ChatWidgetInner />;
}
