import { usePage } from '@inertiajs/react';
import { ArrowLeft, Building2, Handshake, MapPin, Search, Send, Shield, Store, UserRound, Warehouse, Landmark } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import type { FormEvent, KeyboardEvent } from 'react';
import ChatRealtime from '@/components/chat/chat-realtime';
import RealtimeBoundary from '@/components/chat/realtime-boundary';
import type { ChatState } from '@/components/chat/use-chat';
import UserAvatar from '@/components/user-avatar';
import type { ChatContact, ChatMessage } from '@/lib/chat-api';
import { echoEnabled } from '@/lib/echo';
import { cn } from '@/lib/utils';

function formatTime(iso: string): string {
    const date = new Date(iso);
    const time = date.toLocaleTimeString('pl-PL', { hour: '2-digit', minute: '2-digit' });

    return date.toDateString() === new Date().toDateString()
        ? time
        : `${date.toLocaleDateString('pl-PL', { day: '2-digit', month: '2-digit' })} ${time}`;
}

type Filter = { id: string; label: string; icon: LucideIcon; test: (contact: ChatContact) => boolean };

/** The filters of the contact list; only those that match somebody are shown. */
const FILTERS: Filter[] = [
    { id: 'owner', label: 'Wł. organizacji', icon: Building2, test: (c) => c.kind === 'owner' },
    { id: 'shop', label: 'Sklepy', icon: Store, test: (c) => c.kind === 'shop' },
    { id: 'warehouse', label: 'Hurtownie', icon: Warehouse, test: (c) => c.kind === 'warehouse' },
    { id: 'headquarters', label: 'Gł. siedziby', icon: Landmark, test: (c) => c.level === 'headquarters' },
    { id: 'branch', label: 'Placówki', icon: MapPin, test: (c) => c.level === 'branch' },
    { id: 'worker', label: 'Pracownicy', icon: UserRound, test: (c) => c.level === 'worker' },
    { id: 'partner', label: 'Partnerzy', icon: Handshake, test: (c) => c.organization !== null },
    { id: 'platform', label: 'Administracja', icon: Shield, test: (c) => c.kind === 'platform' },
];

/** The icon that says what a contact is: the role in the structure, a handshake for a person of a partner organization. */
function contactIcon(contact: ChatContact): LucideIcon {
    if (contact.organization !== null) {
        return Handshake;
    }

    if (contact.kind === 'owner') {
        return Building2;
    }

    if (contact.kind === 'platform') {
        return Shield;
    }

    const shop = contact.kind === 'shop';

    return contact.level === 'headquarters' ? Landmark : contact.level === 'branch' ? MapPin : shop ? Store : Warehouse;
}

function ContactList({ chat }: { chat: ChatState }) {
    const [query, setQuery] = useState('');
    const [filter, setFilter] = useState<string | null>(null);
    const filters = useMemo(() => FILTERS.filter((item) => chat.contacts.some(item.test)), [chat.contacts]);
    const active = filters.find((item) => item.id === filter) ?? null;
    const visible = useMemo(
        () => chat.contacts.filter((contact) => contact.name.toLowerCase().includes(query.trim().toLowerCase()) && (active === null || active.test(contact))),
        [chat.contacts, query, active],
    );
    const activeCount = chat.contacts.filter((contact) => contact.active).length;

    return (
        <div className="flex h-full min-h-0 flex-col">
            <div className="border-b border-[#dee2e6] p-3">
                <div className="flex items-center gap-2 rounded-full bg-[#f0f2f5] px-3 py-1.5">
                    <Search className="size-4 text-[#6c757d]" />
                    <input
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                        placeholder="Szukaj osoby"
                        className="w-full bg-transparent text-sm outline-none"
                    />
                </div>
                {filters.length > 1 && (
                    <div className="mt-2 flex flex-wrap gap-1">
                        {filters.map((item) => {
                            const Icon = item.icon;

                            return (
                                <button
                                    key={item.id}
                                    type="button"
                                    onClick={() => setFilter(filter === item.id ? null : item.id)}
                                    aria-pressed={filter === item.id}
                                    className={cn(
                                        'flex items-center gap-1 rounded-full border px-2 py-0.5 text-[11px]',
                                        filter === item.id ? 'border-[#0084ff] bg-[#0084ff] text-white' : 'border-[#ced4da] bg-white text-[#495057] hover:bg-[#f0f2f5]',
                                    )}
                                >
                                    <Icon className="size-3" />
                                    {item.label}
                                </button>
                            );
                        })}
                    </div>
                )}
                <p className="mt-2 text-xs text-[#6c757d]">
                    Aktywni: {activeCount} z {chat.contacts.length} osób{active ? ` · filtr: ${active.label} (${visible.length})` : ''}
                </p>
            </div>

            <ul className="min-h-0 flex-1 overflow-y-auto">
                {visible.length === 0 && (
                    <li className="p-4 text-sm text-[#6c757d]">
                        {chat.contacts.length === 0
                            ? 'W Twojej organizacji nie ma jeszcze innych osób.'
                            : 'Nikogo nie znaleziono.'}
                    </li>
                )}
                {visible.map((contact) => (
                    <li key={contact.id}>
                        <button
                            type="button"
                            onClick={() => chat.select(contact.id)}
                            className={cn(
                                'flex w-full items-center gap-3 px-3 py-2 text-left hover:bg-[#f0f2f5]',
                                chat.selectedId === contact.id && 'bg-[#e7f3ff]',
                            )}
                        >
                            <span className="relative shrink-0">
                                <UserAvatar src={contact.avatar_url} name={contact.name} size={40} active={contact.active} />
                                {(() => {
                                    const Icon = contactIcon(contact);

                                    return (
                                        <span className="absolute -right-1 -bottom-1 flex size-4 items-center justify-center rounded-full border border-white bg-[#6c757d] text-white" title={contact.role}>
                                            <Icon className="size-2.5" />
                                        </span>
                                    );
                                })()}
                            </span>
                            <span className="min-w-0 flex-1">
                                <span className="flex items-center justify-between gap-2">
                                    <span className={cn('truncate text-sm', contact.unread > 0 ? 'font-bold' : 'font-medium')}>
                                        {contact.name}
                                    </span>
                                    {contact.last_message && (
                                        <span className="shrink-0 text-[11px] text-[#6c757d]">
                                            {formatTime(contact.last_message.created_at)}
                                        </span>
                                    )}
                                </span>
                                <span className="flex items-center justify-between gap-2">
                                    <span
                                        className={cn(
                                            'truncate text-xs',
                                            chat.typingIds.includes(contact.id)
                                                ? 'font-semibold italic text-[#0084ff]'
                                                : contact.unread > 0
                                                  ? 'font-semibold text-[#212529]'
                                                  : 'text-[#6c757d]',
                                        )}
                                    >
                                        {chat.typingIds.includes(contact.id)
                                            ? 'pisze…'
                                            : contact.last_message
                                              ? `${contact.last_message.mine ? 'Ty: ' : ''}${contact.last_message.body}`
                                              : `${contact.organization ? `${contact.organization} · ` : ''}${contact.role}`}
                                    </span>
                                    {contact.unread > 0 && (
                                        <span className="shrink-0 rounded-full bg-[#dc3545] px-1.5 py-0.5 text-[10px] font-bold text-white">
                                            {contact.unread}
                                        </span>
                                    )}
                                </span>
                            </span>
                        </button>
                    </li>
                ))}
            </ul>
        </div>
    );
}

function Bubble({ message, mine }: { message: ChatMessage; mine: boolean }) {
    return (
        <div className={cn('flex', mine ? 'justify-end' : 'justify-start')}>
            <div
                className={cn(
                    'max-w-[80%] rounded-2xl px-3 py-1.5 text-sm',
                    mine ? 'rounded-br-sm bg-[#0084ff] text-white' : 'rounded-bl-sm bg-[#e4e6eb] text-[#050505]',
                )}
            >
                <p className="whitespace-pre-wrap break-words">{message.body}</p>
                <p className={cn('mt-0.5 text-right text-[10px]', mine ? 'text-white/70' : 'text-[#65676b]')}>
                    {formatTime(message.created_at)}
                    {mine && message.read_at && ' · przeczytana'}
                </p>
            </div>
        </div>
    );
}

function Conversation({ chat, onBack }: { chat: ChatState; onBack?: () => void }) {
    const myId = usePage().props.auth?.user?.id;
    const [draft, setDraft] = useState('');
    const listRef = useRef<HTMLDivElement>(null);
    const lastId = chat.messages[chat.messages.length - 1]?.id;
    const typing = chat.selectedId !== null && chat.typingIds.includes(chat.selectedId);

    // Zawsze pokazujemy dół rozmowy (ostatnią wiadomość): przewijamy sam kontener, a nie całą stronę
    useEffect(() => {
        const list = listRef.current;

        if (!list) {
            return;
        }

        list.scrollTop = list.scrollHeight;
        const frame = requestAnimationFrame(() => {
            list.scrollTop = list.scrollHeight;
        });

        return () => cancelAnimationFrame(frame);
    }, [lastId, chat.messages.length, chat.selectedId, chat.selected?.id, typing]);

    if (!chat.selected) {
        return (
            <div className="flex h-full items-center justify-center p-6 text-center text-sm text-[#6c757d]">
                Wybierz osobę z listy, aby zacząć rozmowę.
            </div>
        );
    }

    const submit = async (event?: FormEvent) => {
        event?.preventDefault();

        if (await chat.send(draft)) {
            setDraft('');
        }
    };

    const onKeyDown = (event: KeyboardEvent<HTMLTextAreaElement>) => {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            void submit();
        }
    };

    return (
        <div className="flex h-full min-h-0 flex-col">
            <div className="flex items-center gap-3 border-b border-[#dee2e6] px-3 py-2">
                {onBack && (
                    <button type="button" onClick={onBack} aria-label="Wróć do listy" className="text-[#0084ff]">
                        <ArrowLeft className="size-5" />
                    </button>
                )}
                <UserAvatar src={chat.selected.avatar_url} name={chat.selected.name} size={36} active={chat.selected.active} />
                <div className="min-w-0">
                    <p className="truncate text-sm font-semibold">{chat.selected.name}</p>
                    <p className={cn('truncate text-xs', typing ? 'font-semibold text-[#0084ff]' : 'text-[#6c757d]')}>
                        {typing ? 'pisze do Ciebie…' : `${chat.selected.active ? 'Aktywny teraz' : 'Nieaktywny'} · ${chat.selected.organization ? `${chat.selected.organization} · ` : ''}${chat.selected.role}`}
                    </p>
                </div>
            </div>

            <div ref={listRef} className="min-h-0 flex-1 space-y-2 overflow-y-auto bg-white p-3">
                {chat.messages.length === 0 && (
                    <p className="pt-6 text-center text-sm text-[#6c757d]">Brak wiadomości. Napisz pierwszą.</p>
                )}
                {chat.messages.map((message) => (
                    <Bubble key={message.id} message={message} mine={message.sender_id === myId} />
                ))}
                {typing && (
                    <div className="flex justify-start" aria-live="polite">
                        <div className="flex items-center gap-1 rounded-2xl rounded-bl-sm bg-[#e4e6eb] px-3 py-2" title="Rozmówca pisze">
                            <span className="size-1.5 animate-bounce rounded-full bg-[#65676b] [animation-delay:-0.3s]" />
                            <span className="size-1.5 animate-bounce rounded-full bg-[#65676b] [animation-delay:-0.15s]" />
                            <span className="size-1.5 animate-bounce rounded-full bg-[#65676b]" />
                        </div>
                    </div>
                )}
            </div>

            <form onSubmit={submit} className="border-t border-[#dee2e6] p-2">
                {chat.error && <p className="mb-1 text-xs text-[#dc3545]">{chat.error}</p>}
                <div className="flex items-end gap-2">
                    <textarea
                        value={draft}
                        onChange={(e) => {
                            setDraft(e.target.value);

                            if (e.target.value.trim() !== '') {
                                chat.notifyTyping();
                            }
                        }}
                        onKeyDown={onKeyDown}
                        rows={1}
                        maxLength={2000}
                        placeholder="Aa"
                        className="max-h-28 min-h-9 flex-1 resize-none rounded-2xl bg-[#f0f2f5] px-3 py-2 text-sm outline-none"
                    />
                    <button
                        type="submit"
                        disabled={chat.sending || draft.trim() === ''}
                        aria-label="Wyślij"
                        className="flex size-9 shrink-0 items-center justify-center rounded-full bg-[#0084ff] text-white disabled:opacity-40"
                    >
                        <Send className="size-4" />
                    </button>
                </div>
            </form>
        </div>
    );
}

/**
 * Okno czatu. `page`: lista osób obok rozmowy (strona /chat), `widget`: jedno z dwóch widoków
 * (lista albo rozmowa) w okienku w rogu ekranu.
 */
export default function ChatBox({ chat, variant }: { chat: ChatState; variant: 'page' | 'widget' }) {
    const userId = usePage().props.auth?.user?.id;

    return (
        <>
            {echoEnabled && userId && (
                <RealtimeBoundary>
                    <ChatRealtime userId={userId} onMessage={chat.receive} onTyping={chat.markTyping} onConnected={chat.setRealtime} />
                </RealtimeBoundary>
            )}

            {variant === 'page' ? (
                <div className="grid h-[70vh] min-h-96 overflow-hidden rounded border border-[#dee2e6] bg-white md:grid-cols-[320px_1fr]">
                    <div className={cn('min-h-0 border-r border-[#dee2e6]', chat.selectedId !== null && 'hidden md:block')}>
                        <ContactList chat={chat} />
                    </div>
                    <div className={cn('min-h-0', chat.selectedId === null && 'hidden md:block')}>
                        <Conversation chat={chat} onBack={chat.back} />
                    </div>
                </div>
            ) : chat.selectedId === null ? (
                <ContactList chat={chat} />
            ) : (
                <Conversation chat={chat} onBack={chat.back} />
            )}
        </>
    );
}
