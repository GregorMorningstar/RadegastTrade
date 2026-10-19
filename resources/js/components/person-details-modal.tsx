import { MessageCircle } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import UserAvatar from '@/components/user-avatar';
import { chatWindow } from '@/lib/chat-window';

type Person = {
    id: number;
    name: string;
    email: string;
    phone: string | null;
    role: string;
    units: string[];
    avatar_url: string;
    is_active: boolean;
    is_me: boolean;
    last_login: string | null;
    created_at: string | null;
};

function maskPhone(phone: string): string {
    return phone.length > 5 ? `${phone.slice(0, 3)} ••• ••• ${phone.slice(-2)}` : '•••';
}

/**
 * Small window with a person's details. The data is fetched only when the window opens (never embedded in the page),
 * the server returns a whitelist of fields and only for people of the same organization; the phone number is masked until revealed.
 */
export default function PersonDetailsModal({
    personId,
    onClose,
}: {
    personId: number | null;
    onClose: () => void;
}) {
    const [person, setPerson] = useState<Person | null>(null);
    const [error, setError] = useState(false);
    const [showPhone, setShowPhone] = useState(false);

    useEffect(() => {
        setPerson(null);
        setError(false);
        setShowPhone(false);

        if (personId === null) {
            return;
        }

        const controller = new AbortController();

        fetch(`/platform/organization/people/${personId}`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            signal: controller.signal,
        })
            .then((response) => (response.ok ? response.json() : Promise.reject(new Error(String(response.status)))))
            .then((data: Person) => setPerson(data))
            .catch((e: unknown) => {
                if (!(e instanceof DOMException && e.name === 'AbortError')) {
                    setError(true);
                }
            });

        return () => controller.abort();
    }, [personId]);

    return (
        <Dialog open={personId !== null} onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="sm:max-w-sm">
                <DialogHeader>
                    <DialogTitle>Szczegóły osoby</DialogTitle>
                    <DialogDescription className="sr-only">Dane osoby z Twojej organizacji</DialogDescription>
                </DialogHeader>

                {error && <p className="text-sm text-[#dc3545]">Nie udało się pobrać danych tej osoby.</p>}
                {!person && !error && <p className="text-sm text-[#6c757d]">Ładowanie…</p>}

                {person && (
                    <div className="space-y-4 text-sm">
                        <div className="flex items-center gap-3">
                            <UserAvatar src={person.avatar_url} name={person.name} size={48} />
                            <div className="min-w-0">
                                <p className="truncate font-semibold">{person.name}</p>
                                <span className="rounded bg-[#007bff] px-2 py-0.5 text-xs text-white">{person.role}</span>
                                {!person.is_active && (
                                    <span className="ml-1 rounded bg-[#dc3545] px-2 py-0.5 text-xs text-white">Konto nieaktywne</span>
                                )}
                            </div>
                        </div>

                        <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2">
                            <dt className="text-[#6c757d]">E-mail</dt>
                            <dd className="break-all">{person.email}</dd>

                            <dt className="text-[#6c757d]">Telefon</dt>
                            <dd>
                                {person.phone ? (
                                    <>
                                        {showPhone ? person.phone : maskPhone(person.phone)}{' '}
                                        <button
                                            type="button"
                                            onClick={() => setShowPhone((v) => !v)}
                                            className="text-xs text-[#007bff] hover:underline"
                                        >
                                            {showPhone ? 'ukryj' : 'pokaż'}
                                        </button>
                                    </>
                                ) : (
                                    '—'
                                )}
                            </dd>

                            <dt className="text-[#6c757d]">Jednostka</dt>
                            <dd>{person.units.length ? person.units.join(', ') : '—'}</dd>

                            <dt className="text-[#6c757d]">Ostatnie logowanie</dt>
                            <dd>{person.last_login ?? '—'}</dd>

                            <dt className="text-[#6c757d]">Konto od</dt>
                            <dd>{person.created_at ?? '—'}</dd>
                        </dl>

                        {!person.is_me && person.is_active && (
                            <button
                                type="button"
                                onClick={() => {
                                    chatWindow.openWith(person.id);
                                    onClose();
                                }}
                                className="flex w-full items-center justify-center gap-2 rounded bg-[#0084ff] px-3 py-2 text-white hover:bg-[#0073e6]"
                            >
                                <MessageCircle className="size-4" />
                                Napisz wiadomość
                            </button>
                        )}
                    </div>
                )}
            </DialogContent>
        </Dialog>
    );
}
