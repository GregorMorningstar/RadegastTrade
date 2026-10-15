import { router } from '@inertiajs/react';
import { Mail, Phone, ShieldCheck, TriangleAlert, UserMinus, UserPlus, UserRoundCog } from 'lucide-react';
import { useState } from 'react';

type Person = { id: number; name: string; email: string; phone?: string | null; role?: string };

type Candidate = Person & {
    role: string;
    placement: string;
    current: boolean;
    /** The only leader of another branch: cannot be taken away from it */
    blocked: boolean;
    warning: string | null;
};

type Candidates = { current: Person[]; candidates: Candidate[] };

type Props = {
    /** Section heading, e.g. "Kierownicy siedziby" */
    title: string;
    /** Who is being added: "kierownika siedziby" / "kierownika placówki" */
    noun: string;
    /** "sklepu" or "hurtowni": the role can only be changed within this group */
    group: 'sklepu' | 'hurtowni';
    /** Name of the unit, used in the confirmations */
    unitName: string;
    /** Current managers / leaders of the unit */
    current: Person[];
    candidatesUrl: string;
    assignUrl: string;
    removeUrl: (userId: number) => string;
    /** PATCH endpoint changing the person's role to a worker of the same group */
    demoteUrl: (userId: number) => string;
    /** Whether the demoted person stays in the unit (a branch leader) or is left without a unit (a manager) */
    staysInUnit: boolean;
    /** How many managers the unit must keep (1 for a branch: its last manager cannot be dismissed) */
    minimum?: number;
};

/**
 * Managers of a headquarters or leaders of a branch. The current ones are shown as cards with their data and can be
 * dismissed ("Odwołaj": they stay in the organization without a unit) or moved to the worker role of the same group
 * (a warehouse person becomes a warehouse worker, a shop person a shop worker). There may be several (shift work), and
 * more can be added from the people of the organization. A person works in at most one unit, so adding someone who
 * works elsewhere takes them out of there; the consequences are shown before the change and have to be confirmed.
 */
export default function UnitStaffPicker({ title, noun, group, unitName, current, candidatesUrl, assignUrl, removeUrl, demoteUrl, staysInUnit, minimum = 0 }: Props) {
    const [open, setOpen] = useState(false);
    const [data, setData] = useState<Candidates | null>(null);
    const [selected, setSelected] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    const toggle = async () => {
        const next = !open;
        setOpen(next);
        setSelected('');
        setError(null);

        if (!next) {
            return;
        }

        try {
            const response = await fetch(candidatesUrl, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });

            if (!response.ok) {
                throw new Error(String(response.status));
            }

            setData((await response.json()) as Candidates);
        } catch {
            setError('Nie udało się pobrać listy osób.');
        }
    };

    const candidate = data?.candidates.find((item) => String(item.id) === selected);

    const options = {
        preserveScroll: true,
        onStart: () => setBusy(true),
        onFinish: () => setBusy(false),
        onSuccess: () => {
            setOpen(false);
            setSelected('');
            setError(null);
        },
        onError: (errors: Record<string, string>) => setError(errors.user_id ?? errors.role ?? 'Nie udało się zapisać zmiany.'),
    };

    const add = () => {
        if (!candidate) {
            return;
        }

        const details = candidate.warning ? `\n\nUwaga:\n- ${candidate.warning}` : '';

        if (confirm(`Dodać: ${candidate.name} jako ${noun} "${unitName}"?${details}`)) {
            router.post(assignUrl, { user_id: candidate.id }, options);
        }
    };

    // a unit that has to keep a manager (a branch) cannot lose its last one
    const locked = current.length <= minimum;
    const lockedHint = `${unitName} musi mieć co najmniej ${minimum === 1 ? 'jednego kierownika' : `${minimum} kierowników`}. Najpierw dodaj kolejnego.`;

    const lastWarning = current.length <= 1 ? `\n\nUwaga: to jedyna osoba w tej roli, "${unitName}" zostanie bez kierownika.` : '';

    const dismiss = (person: Person) => {
        if (locked) {
            return;
        }

        if (confirm(`Odwołać: ${person.name} z funkcji ${noun} "${unitName}"? Osoba zostaje w organizacji, ale nie jest już przypisana do tej jednostki.${lastWarning}`)) {
            router.delete(removeUrl(person.id), options);
        }
    };

    const demote = (person: Person) => {
        if (locked) {
            return;
        }

        const where = staysInUnit ? `Zostanie pracownikiem ${group} w jednostce "${unitName}".` : `Zostanie pracownikiem ${group} bez przypisania do jednostki.`;

        if (confirm(`Zmienić rolę: ${person.name} na pracownika ${group}? ${where} Straci uprawnienia kierownika.${lastWarning}`)) {
            router.patch(demoteUrl(person.id), { role: 'worker' }, options);
        }
    };

    return (
        <div className="mt-4 border-t border-[#dee2e6] pt-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h4 className="flex items-center gap-2 text-sm font-semibold">
                    {title}
                    <span className="rounded-full bg-[#e7f1ff] px-2 py-0.5 text-xs font-medium text-[#007bff]">{current.length}</span>
                </h4>
                <button
                    type="button"
                    onClick={toggle}
                    className="flex items-center gap-1 rounded border border-[#007bff] px-3 py-1 text-sm text-[#007bff] hover:bg-[#e7f1ff]"
                >
                    <UserPlus className="size-4" />
                    {open ? 'Anuluj' : 'Dodaj kierownika z organizacji'}
                </button>
            </div>

            {current.length === 0 ? (
                <p className="mt-2 rounded border border-dashed border-[#ced4da] px-3 py-2 text-sm text-[#6c757d]">
                    Brak kierownika. Dodaj osobę z organizacji lub zaproś nową.
                </p>
            ) : (
                <ul className="mt-3 grid gap-2 md:grid-cols-2 xl:grid-cols-3">
                    {current.map((person) => (
                        <li key={person.id} className="rounded border border-[#cfe2ff] bg-[#f8fbff] p-3 text-sm">
                            <p className="flex items-center gap-1.5 font-semibold">
                                <ShieldCheck className="size-4 text-[#007bff]" />
                                {person.name}
                            </p>
                            {person.role && <p className="text-xs text-[#6c757d]">{person.role}</p>}
                            <p className="mt-1 flex items-center gap-1.5 break-all text-xs text-[#495057]">
                                <Mail className="size-3.5 shrink-0 text-[#6c757d]" />
                                {person.email}
                            </p>
                            <p className="flex items-center gap-1.5 text-xs text-[#495057]">
                                <Phone className="size-3.5 shrink-0 text-[#6c757d]" />
                                {person.phone ?? 'brak numeru'}
                            </p>
                            <div className="mt-2 flex flex-wrap gap-2">
                                <button
                                    type="button"
                                    onClick={() => demote(person)}
                                    disabled={busy || locked}
                                    title={locked ? lockedHint : `Zmień rolę na pracownika ${group}`}
                                    className="flex items-center gap-1 rounded border border-[#ced4da] bg-white px-2 py-1 text-xs hover:bg-[#f1f3f5] disabled:opacity-50"
                                >
                                    <UserRoundCog className="size-3.5" />
                                    Zmień na pracownika
                                </button>
                                <button
                                    type="button"
                                    onClick={() => dismiss(person)}
                                    disabled={busy || locked}
                                    title={locked ? lockedHint : 'Odwołaj z funkcji'}
                                    className="flex items-center gap-1 rounded border border-[#f1aeb5] bg-white px-2 py-1 text-xs text-[#dc3545] hover:bg-[#fff5f5] disabled:opacity-50"
                                >
                                    <UserMinus className="size-3.5" />
                                    Odwołaj
                                </button>
                            </div>
                        </li>
                    ))}
                </ul>
            )}
            {current.length > 0 && (
                <p className={`mt-2 text-xs ${locked ? 'text-[#664d03]' : 'text-[#6c757d]'}`}>
                    {locked
                        ? `Jedyny kierownik nie może zostać odwołany ani zmieniony: ${lockedHint}`
                        : 'Może być kilku kierowników, np. przy pracy zmianowej.'}
                </p>
            )}

            {error && !open && <p className="mt-2 text-sm text-[#dc3545]">{error}</p>}

            {open && (
                <div className="mt-3 space-y-3">
                    {error && <p className="text-sm text-[#dc3545]">{error}</p>}
                    {!error && data === null && <p className="text-sm text-[#6c757d]">Ładowanie…</p>}

                    {data && data.candidates.length === 0 && (
                        <p className="text-sm text-[#6c757d]">W organizacji nie ma jeszcze osób, które można dodać. Zaproś kierownika.</p>
                    )}

                    {data && data.candidates.length > 0 && (
                        <>
                            <select
                                value={selected}
                                onChange={(event) => setSelected(event.target.value)}
                                aria-label={`Nowy ${noun}`}
                                className="w-full max-w-xl rounded border border-[#ced4da] px-3 py-1.5 text-sm"
                            >
                                <option value="">— wybierz osobę z organizacji —</option>
                                {data.candidates.map((item) => (
                                    <option key={item.id} value={item.id} disabled={item.current || item.blocked}>
                                        {item.name} ({item.email}) — {item.placement}
                                        {item.blocked ? ' (jedyny kierownik placówki, nie można przenieść)' : ''}
                                    </option>
                                ))}
                            </select>

                            {candidate?.warning && (
                                <div role="alert" className="max-w-xl rounded border border-[#ffc107] bg-[#fff8e1] px-3 py-2 text-sm text-[#664d03]">
                                    <p className="flex items-center gap-1.5 font-semibold">
                                        <TriangleAlert className="size-4" />
                                        Uwaga przed zmianą
                                    </p>
                                    <p className="mt-1">{candidate.warning}</p>
                                    <p className="mt-1 text-xs">Osoba może pracować w najwyżej jednej jednostce (siedzibie lub placówce).</p>
                                </div>
                            )}

                            <button
                                type="button"
                                onClick={add}
                                disabled={!candidate || busy}
                                className="rounded bg-[#007bff] px-4 py-1.5 text-sm text-white disabled:opacity-50"
                            >
                                {busy ? 'Zapisuję…' : 'Dodaj'}
                            </button>
                        </>
                    )}
                </div>
            )}
        </div>
    );
}
