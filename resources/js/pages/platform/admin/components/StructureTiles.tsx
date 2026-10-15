import { usePage } from '@inertiajs/react';
import { Building2, ChevronDown, ChevronLeft, ChevronRight, Mail, MapPin, MessageCircle, Phone, Power, Search, User, Users, X } from 'lucide-react';
import { createContext, useContext, useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import Barcode from 'react-barcode';
import { chatWindow } from '@/lib/chat-window';
import { roleColor } from '@/lib/role-colors';
import { cn } from '@/lib/utils';

export type Person = {
    id: number;
    name: string;
    email: string;
    phone: string | null;
    barcode: string | null;
    role: string;
    /** Role value, e.g. shop_leader (the colour of the role badge follows from it) */
    role_value?: string;
    /** Whether the account may be used (switched by the organization owner) */
    is_active?: boolean;
};

export type Unit = {
    id: number;
    name: string;
    barcode: string | null;
    nip: string | null;
    email: string | null;
    phone: string | null;
    address: string | null;
    people: Person[];
};

export type Headquarters = Unit & { organization?: string | null; branches: Unit[] };

export type Paging<T> = { data: T[]; current_page: number; last_page: number; total: number };

/** What the panel on the right shows: a person, a unit (headquarters / branch) or nothing. */
export type Selected =
    | { kind: 'person'; person: Person }
    | { kind: 'unit'; unit: Unit; title: string; unitKind: string; organization?: string | null };

type Selection = {
    selected: Selected | null;
    select: (selected: Selected | null) => void;
    /** Switches a person's active flag locally after the server accepted it */
    setActive: (personId: number, active: boolean) => void;
    /** Locally changed active flags (person id -> flag) */
    overrides: Record<number, boolean>;
};

const SelectionContext = createContext<Selection>({ selected: null, select: () => undefined, setActive: () => undefined, overrides: {} });

export function useStructureSelection(): Selection {
    return useContext(SelectionContext);
}

/** Whether the person's account is active, taking the locally switched flags into account. */
function isActive(person: Person, overrides: Record<number, boolean>): boolean {
    return overrides[person.id] ?? person.is_active !== false;
}

/** Smoothly expands / collapses its content (animated height and opacity). */
export function Collapse({ open, children }: { open: boolean; children: ReactNode }) {
    return (
        <div
            className={cn(
                'grid transition-[grid-template-rows,opacity] duration-300 ease-in-out',
                open ? 'grid-rows-[1fr] opacity-100' : 'grid-rows-[0fr] opacity-0',
            )}
            aria-hidden={!open}
        >
            <div className="min-h-0 overflow-hidden">{children}</div>
        </div>
    );
}

/** EAN-13 barcode (bars with the digits underneath). */
function BarcodeImage({ value, title }: { value: string; title: string }) {
    return (
        <span title={`${title}: ${value}`} className="inline-block max-w-full overflow-hidden leading-none">
            <Barcode value={value} format="EAN13" width={1.4} height={44} fontSize={12} margin={0} background="transparent" />
        </span>
    );
}

/** Coloured badge of the role of a person. */
export function RoleBadge({ person }: { person: Person }) {
    return <span className={cn('inline-block whitespace-nowrap rounded px-2 py-0.5 text-xs font-medium', roleColor(person.role_value))}>{person.role}</span>;
}

/** Green / grey dot and word: whether the account is active. */
function ActiveFlag({ active, compact = false }: { active: boolean; compact?: boolean }) {
    return (
        <span className={cn('inline-flex items-center gap-1 whitespace-nowrap text-xs', active ? 'text-[#1e7e34]' : 'text-[#6c757d]')} title={active ? 'Konto aktywne' : 'Konto nieaktywne'}>
            <span className={cn('size-2 rounded-full', active ? 'bg-[#28a745]' : 'bg-[#adb5bd]')} />
            {!compact && (active ? 'aktywny' : 'nieaktywny')}
        </span>
    );
}

/** Details of a person: role, contact, barcode, chat and (for the organization owner) the active switch. */
function PersonDetails({ person }: { person: Person }) {
    const { setActive, overrides } = useStructureSelection();
    const viewerRole = usePage().props.auth?.user?.role;
    const canSwitch = viewerRole === 'organization_owner' && person.role_value !== 'organization_owner';
    const active = isActive(person, overrides);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const toggle = async () => {
        const next = !active;

        if (!next && !confirm(`Dezaktywować konto: ${person.name}? Osoba zostanie wylogowana i nie zaloguje się, dopóki go nie włączysz.`)) {
            return;
        }

        setBusy(true);
        setError(null);

        try {
            const token = decodeURIComponent(document.cookie.split('; ').find((row) => row.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '');
            const response = await fetch(`/memberships/people/${person.id}/active`, {
                method: 'PATCH',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': token },
                body: JSON.stringify({ active: next }),
            });

            if (!response.ok) {
                throw new Error(String(response.status));
            }

            setActive(person.id, next);
        } catch {
            setError('Nie udało się zmienić statusu konta.');
        } finally {
            setBusy(false);
        }
    };

    return (
        <>
            <div className="min-w-0 px-8">
                <h5 className="text-lg font-semibold">{person.name}</h5>
                <div className="mt-1 flex flex-wrap items-center justify-center gap-2">
                    <RoleBadge person={person} />
                    <ActiveFlag active={active} />
                </div>
            </div>

            <p className="flex items-center justify-center gap-2 break-all text-sm text-[#495057]">
                <Mail className="size-4 shrink-0 text-[#6c757d]" />
                {person.email}
            </p>
            <p className="flex items-center justify-center gap-2 text-sm text-[#495057]">
                <Phone className="size-4 shrink-0 text-[#6c757d]" />
                {person.phone ?? 'brak numeru'}
            </p>

            {person.barcode && (
                <div className="flex w-full justify-center rounded bg-[#f8f9fa] py-3">
                    <BarcodeImage value={person.barcode} title="Kod użytkownika" />
                </div>
            )}

            <button
                type="button"
                onClick={() => chatWindow.openWith(person.id)}
                className="flex w-full max-w-sm items-center justify-center gap-2 rounded bg-[#0084ff] px-3 py-2 text-sm text-white hover:bg-[#0073e6]"
            >
                <MessageCircle className="size-4" />
                Czat
            </button>

            {canSwitch && (
                <>
                    <button
                        type="button"
                        onClick={toggle}
                        disabled={busy}
                        className={cn(
                            'flex w-full max-w-sm items-center justify-center gap-2 rounded border px-3 py-2 text-sm disabled:opacity-60',
                            active ? 'border-[#dc3545] text-[#dc3545] hover:bg-[#fff5f5]' : 'border-[#28a745] text-[#1e7e34] hover:bg-[#f1fbf3]',
                        )}
                    >
                        <Power className="size-4" />
                        {busy ? 'Zapisuję…' : active ? 'Dezaktywuj konto' : 'Aktywuj konto'}
                    </button>
                    {error && <p className="text-xs text-[#dc3545]">{error}</p>}
                </>
            )}
        </>
    );
}

/** Details of a unit: kind, organization, contact, barcode and the people working in it (click one to see them). */
function UnitDetails({ selected }: { selected: Extract<Selected, { kind: 'unit' }> }) {
    const { select, overrides } = useStructureSelection();
    const { unit } = selected;
    const empty = !unit.nip && !unit.phone && !unit.email && !unit.address;

    return (
        <>
            <div className="min-w-0 px-8">
                <h5 className="text-lg font-semibold">{selected.title}</h5>
                <p className="text-sm text-[#6c757d]">
                    {selected.unitKind}
                    {selected.organization ? ` · organizacja: ${selected.organization}` : ''}
                </p>
                {selected.title !== unit.name && <p className="mt-0.5 text-xs text-[#6c757d]">{unit.name}</p>}
            </div>

            <div className="w-full space-y-1.5 text-sm text-[#495057]">
                {unit.nip && <p>NIP {unit.nip}</p>}
                {unit.phone && (
                    <p className="flex items-center justify-center gap-2">
                        <Phone className="size-4 text-[#6c757d]" />
                        {unit.phone}
                    </p>
                )}
                {unit.email && (
                    <p className="flex items-center justify-center gap-2 break-all">
                        <Mail className="size-4 shrink-0 text-[#6c757d]" />
                        {unit.email}
                    </p>
                )}
                {unit.address && (
                    <p className="flex items-center justify-center gap-2">
                        <MapPin className="size-4 shrink-0 text-[#6c757d]" />
                        {unit.address}
                    </p>
                )}
                {empty && <p className="text-[#6c757d]">Brak danych kontaktowych.</p>}
            </div>

            {unit.barcode && (
                <div className="flex w-full justify-center rounded bg-[#f8f9fa] py-3">
                    <BarcodeImage value={unit.barcode} title="Kod jednostki" />
                </div>
            )}

            <div className="w-full text-left">
                <h6 className="mb-1.5 flex items-center justify-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-[#6c757d]">
                    <Users className="size-3.5" />
                    Osoby ({unit.people.length})
                </h6>
                {unit.people.length === 0 ? (
                    <p className="text-center text-xs text-[#6c757d]">Brak osób.</p>
                ) : (
                    <ul className="space-y-1">
                        {unit.people.map((person) => (
                            <li key={person.id}>
                                <button
                                    type="button"
                                    onClick={() => select({ kind: 'person', person })}
                                    className="flex w-full items-center justify-between gap-2 rounded border border-[#e9ecef] px-2.5 py-1.5 text-left text-sm hover:bg-[#f8f9fa]"
                                >
                                    <span className="min-w-0 truncate font-medium">{person.name}</span>
                                    <span className="flex shrink-0 items-center gap-2">
                                        <RoleBadge person={person} />
                                        <ActiveFlag active={isActive(person, overrides)} compact />
                                    </span>
                                </button>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}

/** Details of what is selected, shown beside the list (filling the space on the right). */
function DetailsPanel() {
    const { selected, select } = useStructureSelection();

    return (
        <aside className="rounded border border-[#cfe2ff] bg-white p-4 shadow-sm lg:sticky lg:top-4">
            {selected === null ? (
                <div className="flex flex-col items-center gap-2 py-8 text-center text-sm text-[#6c757d]">
                    <User className="size-8" />
                    Kliknij osobę, siedzibę lub placówkę na liście, aby zobaczyć szczegóły.
                </div>
            ) : (
                <div className="relative mx-auto flex max-w-xl flex-col items-center gap-3 text-center">
                    <button
                        type="button"
                        onClick={() => select(null)}
                        aria-label="Zamknij szczegóły"
                        className="absolute right-0 top-0 rounded p-1 text-[#6c757d] hover:bg-[#f1f3f5]"
                    >
                        <X className="size-4" />
                    </button>

                    {selected.kind === 'person' ? <PersonDetails person={selected.person} /> : <UnitDetails selected={selected} />}
                </div>
            )}
        </aside>
    );
}

/** Layout of a structure view: the list on the left, details of the selected person or unit right next to it. */
export function StructureLayout({ children, onSelect }: { children: ReactNode; onSelect?: (selected: Selected | null) => void }) {
    const [selected, setSelected] = useState<Selected | null>(null);
    const [overrides, setOverrides] = useState<Record<number, boolean>>({});

    // the page around the list may react to what is clicked (e.g. open the details of the clicked headquarters)
    const select = (next: Selected | null) => {
        setSelected(next);
        onSelect?.(next);
    };

    return (
        <SelectionContext.Provider value={{ selected, select, overrides, setActive: (id, active) => setOverrides((previous) => ({ ...previous, [id]: active })) }}>
            <div className="grid grid-cols-1 items-start justify-start gap-4 lg:grid-cols-[minmax(0,auto)_minmax(20rem,1fr)]">
                <div className="min-w-0">{children}</div>
                <div className={cn('lg:order-last', selected === null ? 'hidden lg:block' : 'order-first')}>
                    <DetailsPanel />
                </div>
            </div>
        </SelectionContext.Provider>
    );
}

/** Tiles of a fixed width that wrap as the screen allows, so the details panel always sits right next to them. */
export function TileGrid({ children }: { children: ReactNode }) {
    return <div className="flex flex-wrap items-start gap-x-3">{children}</div>;
}

/** One person in a list: name, role badge, active flag and the chat button. Clicking shows the details on the right. */
function PersonRow({ person }: { person: Person }) {
    const { selected, select, overrides } = useStructureSelection();
    const current = selected?.kind === 'person' && selected.person.id === person.id;
    const active = isActive(person, overrides);

    return (
        <div
            className={cn(
                'flex items-center gap-2 rounded border px-3 py-2 text-sm transition-colors',
                current ? 'border-[#007bff] bg-[#e7f1ff]' : 'border-[#e9ecef] bg-white hover:bg-[#f8f9fa]',
                !active && 'opacity-70',
            )}
        >
            <button
                type="button"
                onClick={() => select(current ? null : { kind: 'person', person })}
                aria-pressed={current}
                title="Pokaż szczegóły"
                className="flex min-w-0 flex-1 flex-wrap items-center gap-x-2 gap-y-1 text-left"
            >
                <span className="min-w-0 truncate font-semibold">{person.name}</span>
                <RoleBadge person={person} />
                <ActiveFlag active={active} compact />
            </button>
            <button
                type="button"
                onClick={() => chatWindow.openWith(person.id)}
                title={`Napisz do: ${person.name}`}
                aria-label={`Czat z ${person.name}`}
                className="flex size-8 shrink-0 items-center justify-center rounded-full bg-[#0084ff] text-white hover:bg-[#0073e6]"
            >
                <MessageCircle className="size-4" />
            </button>
        </div>
    );
}

/** People of a unit as a compact list (the empty text when nobody works there). */
export function PeopleTiles({ people, empty }: { people: Person[]; empty: string }) {
    return people.length === 0 ? (
        <p className="text-xs text-[#6c757d]">{empty}</p>
    ) : (
        <div className="space-y-1.5">
            {people.map((person) => (
                <PersonRow key={person.id} person={person} />
            ))}
        </div>
    );
}

/** Short contact lines of a headquarters or a branch (the full details, with the barcode, are in the panel on the right). */
function UnitContact({ unit }: { unit: Unit }) {
    const empty = !unit.nip && !unit.phone && !unit.email && !unit.address;

    return (
        <div className="space-y-1 rounded bg-[#f8f9fa] px-3 py-2 text-xs text-[#495057]">
            {unit.nip && <p>NIP {unit.nip}</p>}
            {unit.phone && (
                <p className="flex items-center gap-1.5">
                    <Phone className="size-3.5 shrink-0 text-[#6c757d]" />
                    {unit.phone}
                </p>
            )}
            {unit.email && (
                <p className="flex items-center gap-1.5 break-all">
                    <Mail className="size-3.5 shrink-0 text-[#6c757d]" />
                    {unit.email}
                </p>
            )}
            {unit.address && (
                <p className="flex items-center gap-1.5">
                    <MapPin className="size-3.5 shrink-0 text-[#6c757d]" />
                    {unit.address}
                </p>
            )}
            {empty && <p className="text-[#6c757d]">Brak danych kontaktowych.</p>}
        </div>
    );
}

/**
 * One unit (the headquarters itself or a branch): a header that opens it and shows its details on the right,
 * and its contact and people when open.
 */
function UnitSection({
    title,
    kind,
    unit,
    organization,
    open,
    onToggle,
}: {
    title: string;
    kind: string;
    unit: Unit;
    organization?: string | null;
    open: boolean;
    onToggle: () => void;
}) {
    const { selected, select } = useStructureSelection();
    const shown = selected?.kind === 'unit' && selected.unit.id === unit.id && selected.title === title;

    const click = () => {
        onToggle();
        // opening a unit shows its details on the right; closing it clears them
        select(open ? (shown ? null : selected) : { kind: 'unit', unit, title, unitKind: kind, organization });
    };

    return (
        <div className={cn('mb-2 rounded border transition-colors', open ? 'border-[#007bff] bg-white' : 'border-[#e9ecef] bg-[#f8f9fa]')}>
            <button type="button" onClick={click} aria-expanded={open} className="flex w-full items-center gap-2 px-3 py-2 text-left">
                <Building2 className="size-4 shrink-0 text-[#6c757d]" />
                <span className="min-w-0 flex-1">
                    <span className="block truncate text-sm font-semibold">{title}</span>
                    <span className="block text-xs text-[#6c757d]">
                        {kind} · {unit.people.length} osób
                    </span>
                </span>
                <ChevronDown className={cn('size-4 shrink-0 text-[#6c757d] transition-transform duration-300', open ? '' : '-rotate-90')} />
            </button>
            <Collapse open={open}>
                <div className="space-y-2 px-3 pb-3 pt-1">
                    <UnitContact unit={unit} />
                    <PeopleTiles people={unit.people} empty="Brak osób." />
                </div>
            </Collapse>
        </div>
    );
}

/** What a headquarters tile shows: only the main headquarters, only its branches, or both. */
export type TileContent = 'headquarters' | 'branches' | 'all';

/**
 * A headquarters tile: the header shows the organization and totals; below are the headquarters itself and each
 * branch as sections, depending on `content`: the pages of the main headquarters show only the headquarters data
 * (contact and managers), the pages of the branches only the branches of each headquarters. Clicking a section opens
 * it (collapsing the others) and shows its details on the right.
 */
export function HeadquartersTile({ headquarters, content = 'all' }: { headquarters: Headquarters; content?: TileContent }) {
    const [active, setActive] = useState<string | null>(null);
    const toggle = (key: string) => setActive(active === key ? null : key);
    const peopleCount = headquarters.people.length + headquarters.branches.reduce((sum, branch) => sum + branch.people.length, 0);
    const branchesOnly = content === 'branches';
    const branchPeople = headquarters.branches.reduce((sum, branch) => sum + branch.people.length, 0);

    return (
        <div className="w-full pb-3 md:w-[34rem]">
            <section className="rounded border border-[#dee2e6] bg-white p-3 shadow-sm">
                <header className="flex items-start gap-2 pb-3">
                    <Building2 className="mt-0.5 size-5 shrink-0 text-[#007bff]" />
                    <div className="min-w-0">
                        <h5 className="font-semibold leading-tight">{headquarters.name}</h5>
                        {branchesOnly && <p className="text-xs text-[#6c757d]">główna siedziba</p>}
                        {headquarters.organization && <p className="text-xs text-[#6c757d]">organizacja: {headquarters.organization}</p>}
                        <p className="text-xs text-[#6c757d]">
                            {content === 'headquarters'
                                ? `${headquarters.people.length} osób w siedzibie`
                                : branchesOnly
                                  ? `${headquarters.branches.length} placówek · ${branchPeople} osób`
                                  : `${headquarters.branches.length} placówek · ${peopleCount} osób`}
                        </p>
                    </div>
                </header>

                {content !== 'branches' && (
                    <UnitSection
                        title="Główna siedziba"
                        kind="kontakt i kierownicy"
                        unit={headquarters}
                        organization={headquarters.organization}
                        open={active === 'hq'}
                        onToggle={() => toggle('hq')}
                    />
                )}

                {content !== 'headquarters' && headquarters.branches.length === 0 && <p className="px-1 text-xs text-[#6c757d]">Brak placówek.</p>}
                {content !== 'headquarters' &&
                    headquarters.branches.map((branch) => (
                        <UnitSection
                            key={branch.id}
                            title={branch.name}
                            kind="placówka"
                            unit={branch}
                            organization={headquarters.organization}
                            open={active === `b${branch.id}`}
                            onToggle={() => toggle(`b${branch.id}`)}
                        />
                    ))}
            </section>
        </div>
    );
}

/**
 * Search field for shops / warehouses / people. Reports the text (after a short pause) through `onSearch`.
 * The server matches it against headquarters, their organization, branches and people.
 */
export function SearchBox({ onSearch, placeholder = 'Szukaj sklepu, hurtowni, placówki lub osoby…' }: { onSearch: (text: string) => void; placeholder?: string }) {
    const [text, setText] = useState('');

    useEffect(() => {
        const timer = window.setTimeout(() => onSearch(text.trim()), 300);

        return () => window.clearTimeout(timer);
    }, [text, onSearch]);

    return (
        <div className="relative mb-3 max-w-md">
            <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-[#6c757d]" />
            <input
                type="search"
                value={text}
                onChange={(event) => setText(event.target.value)}
                maxLength={100}
                placeholder={placeholder}
                aria-label="Szukaj"
                className="w-full rounded border border-[#ced4da] py-1.5 pl-9 pr-3 text-sm"
            />
        </div>
    );
}

/** Previous / next page controls. */
export function Pager({ paging, onPage }: { paging: Paging<unknown>; onPage: (page: number) => void }) {
    if (paging.last_page <= 1) {
        return null;
    }

    return (
        <div className="flex items-center justify-center gap-3 pt-1 text-sm">
            <button
                type="button"
                disabled={paging.current_page <= 1}
                onClick={() => onPage(paging.current_page - 1)}
                className="rounded border border-[#ced4da] p-1 disabled:opacity-40"
                aria-label="Poprzednia strona"
            >
                <ChevronLeft className="size-4" />
            </button>
            <span>
                Strona {paging.current_page} z {paging.last_page} ({paging.total})
            </span>
            <button
                type="button"
                disabled={paging.current_page >= paging.last_page}
                onClick={() => onPage(paging.current_page + 1)}
                className="rounded border border-[#ced4da] p-1 disabled:opacity-40"
                aria-label="Następna strona"
            >
                <ChevronRight className="size-4" />
            </button>
        </div>
    );
}
