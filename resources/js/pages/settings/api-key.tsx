import { Head, router, usePage } from '@inertiajs/react';
import { TriangleAlert } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { destroy, edit, removalCode, reveal, store } from '@/routes/api-key';

type UnitKey = { name: string; prefix: string; can_reveal: boolean; created_at: string | null; last_used_at: string | null; last_used_ip: string | null; removal_pending: boolean };

type Unit = {
    type: 'shops' | 'warehouses';
    level: 'headquarters' | 'branches';
    id: number;
    name: string;
    headquarter: string | null;
    group: string;
    is_headquarters: boolean;
    key: UnitKey | null;
};

type Props = { apiKeys: { allowed: boolean; api_url: string; units: Unit[] } };

function formatDate(value: string | null): string {
    return value ? new Date(value).toLocaleString('pl-PL', { dateStyle: 'short', timeStyle: 'short' }) : 'jeszcze nie użyty';
}

const identity = (unit: Unit) => `${unit.type}:${unit.level}:${unit.id}`;
const target = (unit: Unit) => ({ type: unit.type, level: unit.level, unit: unit.id });

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}

/**
 * API keys of the connectors of units (the program on the computer of a shop headquarters or branch): one key per unit,
 * generated only when the unit has none; removing needs a code sent to the e-mail.
 */
export default function ApiKey({ apiKeys }: Props) {
    const errors = usePage().props.errors as Record<string, string | undefined>;
    const [busy, setBusy] = useState(false);
    const [active, setActive] = useState<string | null>(null);

    return (
        <>
            <Head title="Klucze API" />

            <h1 className="sr-only">Klucze API</h1>

            <div className="space-y-6">
                <Heading variant="small" title="Klucze API konektorów" description="Klucz do programu (konektora), który wysyła dane jednostki, np. z komputera w sklepie. Wysyłamy go na Twój e-mail, w bazie zapisujemy tylko jego skrót." />

                <div className="rounded-lg border p-3 text-sm">
                    <p className="text-muted-foreground">Adres serwera (adres API w konektorze)</p>
                    <p className="font-mono break-all">{apiKeys.api_url}</p>
                    <p className="mt-1 text-xs text-muted-foreground">W programie ApiConnector podaj ten adres i klucz jednostki (nagłówek X-API-KEY albo Bearer). Test połączenia: GET {apiKeys.api_url}/api/ping</p>
                </div>

                {apiKeys.units.length === 0 && <p className="text-sm text-muted-foreground">Nie masz jeszcze jednostek, dla których można wygenerować klucz.</p>}

                <div className="space-y-3">
                    {apiKeys.units.map((unit) => (
                        <UnitCard
                            key={identity(unit)}
                            unit={unit}
                            busy={busy}
                            setBusy={setBusy}
                            error={active === identity(unit) ? (errors.api_key ?? errors.code ?? errors.name) : undefined}
                            onAct={() => setActive(identity(unit))}
                        />
                    ))}
                </div>
            </div>
        </>
    );
}

function UnitCard({ unit, busy, setBusy, error, onAct }: { unit: Unit; busy: boolean; setBusy: (value: boolean) => void; error?: string; onAct: () => void }) {
    const [name, setName] = useState('');
    const [code, setCode] = useState('');
    const [password, setPassword] = useState('');
    const [asking, setAsking] = useState(false);
    const [shownKey, setShownKey] = useState<string | null>(null);
    const [revealError, setRevealError] = useState<string | null>(null);
    const options = { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) };
    const key = unit.key;

    // the shown key disappears from the page after a minute
    useEffect(() => {
        if (shownKey === null) {
            return;
        }

        const timer = window.setTimeout(() => setShownKey(null), 60_000);

        return () => window.clearTimeout(timer);
    }, [shownKey]);

    const showKey = async (event: FormEvent) => {
        event.preventDefault();
        setRevealError(null);
        setBusy(true);

        try {
            const response = await fetch(reveal(target(unit)).url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrfToken() },
                body: JSON.stringify({ password }),
            });
            const data = await response.json().catch(() => ({}));

            if (!response.ok) {
                setRevealError(data.errors?.password?.[0] ?? data.errors?.api_key?.[0] ?? data.message ?? 'Nie udało się pokazać klucza.');

                return;
            }

            setShownKey(data.key as string);
            setAsking(false);
            setPassword('');
        } finally {
            setBusy(false);
        }
    };

    const generate = (event: FormEvent) => {
        event.preventDefault();
        onAct();
        router.post(store(target(unit)).url, { name }, { ...options, onSuccess: () => setName('') });
    };

    const sendCode = () => {
        onAct();
        router.post(removalCode(target(unit)).url, {}, { ...options, onSuccess: () => setCode('') });
    };

    const confirm = (event: FormEvent) => {
        event.preventDefault();
        onAct();
        router.delete(destroy(target(unit)).url, { ...options, data: { code }, onSuccess: () => setCode('') });
    };

    return (
        <section className={`rounded-lg border p-4 text-sm ${unit.is_headquarters ? '' : 'ml-4'}`}>
            <header className="mb-2 flex flex-wrap items-baseline gap-2">
                <span className="rounded bg-muted px-1.5 py-0.5 text-xs">{unit.group}</span>
                <h3 className="font-semibold">{unit.name}</h3>
                <span className="text-xs text-muted-foreground">{unit.is_headquarters ? 'siedziba' : `placówka${unit.headquarter ? ` · ${unit.headquarter}` : ''}`}</span>
            </header>

            {key ? (
                <div className="space-y-3">
                    <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1">
                        <dt className="text-muted-foreground">Konektor</dt>
                        <dd>{key.name}</dd>
                        <dt className="text-muted-foreground">Klucz</dt>
                        <dd className="font-mono">{key.prefix}…</dd>
                        <dt className="text-muted-foreground">Ostatnie użycie</dt>
                        <dd>
                            {formatDate(key.last_used_at)}
                            {key.last_used_ip ? ` (${key.last_used_ip})` : ''}
                        </dd>
                    </dl>

                    {key.can_reveal && (
                        <div className="space-y-2">
                            {shownKey ? (
                                <div className="rounded-lg border bg-muted p-3">
                                    <p className="font-mono break-all select-all">{shownKey}</p>
                                    <div className="mt-2 flex gap-2">
                                        <Button size="sm" variant="outline" onClick={() => navigator.clipboard?.writeText(shownKey)}>
                                            Kopiuj
                                        </Button>
                                        <Button size="sm" variant="ghost" onClick={() => setShownKey(null)}>
                                            Ukryj
                                        </Button>
                                    </div>
                                    <p className="mt-1 text-xs text-muted-foreground">Klucz zniknie z ekranu po minucie.</p>
                                </div>
                            ) : asking ? (
                                <form onSubmit={showKey} className="flex flex-wrap items-center gap-2">
                                    <input
                                        type="password"
                                        value={password}
                                        onChange={(event) => setPassword(event.target.value)}
                                        autoComplete="current-password"
                                        placeholder="Twoje hasło"
                                        aria-label="Twoje hasło"
                                        className="w-48 rounded border border-input px-3 py-1.5"
                                    />
                                    <Button type="submit" size="sm" disabled={busy || password === ''}>
                                        Pokaż klucz
                                    </Button>
                                    <Button type="button" size="sm" variant="ghost" onClick={() => { setAsking(false); setPassword(''); setRevealError(null); }}>
                                        Anuluj
                                    </Button>
                                </form>
                            ) : (
                                <Button size="sm" variant="outline" onClick={() => setAsking(true)}>
                                    Pokaż klucz (po potwierdzeniu hasłem)
                                </Button>
                            )}
                            {revealError && <p className="text-sm text-red-600">{revealError}</p>}
                        </div>
                    )}

                    <div className="flex gap-2 rounded-lg border border-amber-300 bg-amber-50 p-3 text-amber-900">
                        <TriangleAlert className="mt-0.5 size-4 shrink-0" />
                        <p>Ta jednostka ma już aktywny klucz. Aby wygenerować nowy, najpierw usuń obecny. Po usunięciu konektor używający tego klucza przestanie działać. Usunięcie potwierdzisz kodem wysłanym na Twój e-mail.</p>
                    </div>

                    <Button variant="destructive" size="sm" onClick={sendCode} disabled={busy}>
                        {key.removal_pending ? 'Wyślij kod ponownie' : 'Usuń klucz (wyślij kod na e-mail)'}
                    </Button>

                    {key.removal_pending && (
                        <form onSubmit={confirm} className="flex flex-wrap items-center gap-2">
                            <input
                                value={code}
                                onChange={(event) => setCode(event.target.value.replace(/\D/g, '').slice(0, 6))}
                                inputMode="numeric"
                                autoComplete="one-time-code"
                                placeholder="Kod z e-maila"
                                aria-label="Kod z e-maila"
                                className="w-36 rounded border border-input px-3 py-1.5 font-mono tracking-widest"
                            />
                            <Button type="submit" variant="destructive" size="sm" disabled={busy || code.length !== 6}>
                                Potwierdź usunięcie
                            </Button>
                            <span className="text-xs text-muted-foreground">Kod jest ważny 15 minut.</span>
                        </form>
                    )}
                </div>
            ) : (
                <form onSubmit={generate} className="flex flex-wrap items-center gap-2">
                    <input value={name} onChange={(event) => setName(event.target.value)} maxLength={100} placeholder="Nazwa konektora, np. Kasa 1" aria-label="Nazwa konektora" className="min-w-52 flex-1 rounded border border-input px-3 py-1.5" />
                    <Button type="submit" size="sm" disabled={busy}>
                        Wygeneruj klucz i wyślij e-mailem
                    </Button>
                </form>
            )}

            {error && <p className="mt-2 text-sm text-red-600">{error}</p>}
        </section>
    );
}

ApiKey.layout = {
    breadcrumbs: [{ title: 'Klucze API', href: edit() }],
};
