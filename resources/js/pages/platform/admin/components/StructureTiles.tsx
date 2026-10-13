import { Building2, ChevronDown, ChevronLeft, ChevronRight, Mail, MapPin, MessageCircle, Phone, Search, User, X } from 'lucide-react';
import { createContext, useContext, useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import Barcode from 'react-barcode';
import { chatWindow } from '@/lib/chat-window';
import { cn } from '@/lib/utils';

export type Person = {
    id: number;
    name: string;
    email: string;
    phone: string | null;
    barcode: string | null;
    role: string;
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

/** The person whose details are shown in the panel on the right. */
type Selection = { person: Person | null; select: (person: Person | null) => void };

const SelectionContext = createContext<Selection>({ person: null, select: () => undefined });

export function useStructureSelection(): Selection {
    return useContext(SelectionContext);
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

/** Details of the selected person, shown beside the list (filling the space on the right). */
function PersonPanel() {
    const { person, select } = useStructureSelection();

    return (
        <aside className="rounded border border-[#cfe2ff] bg-white p-4 shadow-sm lg:sticky lg:top-4">
            {person === null ? (
                <div className="flex flex-col items-center gap-2 py-8 text-center text-sm text-[#6c757d]">
                    <User className="size-8" />
                    Kliknij osobę na liście, aby zobaczyć jej szczegóły.
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

                    <div className="min-w-0 px-8">
                        <h5 className="text-lg font-semibold">{person.name}</h5>
                        <p className="text-sm text-[#6c757d]">{person.role}</p>
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
                </div>
            )}
        </aside>
    );
}

/** Layout of a structure view: the list on the left, details of the selected person right next to it. */
export function StructureLayout({ children }: { children: ReactNode }) {
    const [person, select] = useState<Person | null>(null);

    return (
        <SelectionContext.Provider value={{ person, select }}>
            <div className="grid grid-cols-1 items-start justify-start gap-4 lg:grid-cols-[minmax(0,auto)_minmax(20rem,1fr)]">
                <div className="min-w-0">{children}</div>
                <div className={cn('lg:order-last', person === null ? 'hidden lg:block' : 'order-first')}>
                    <PersonPanel />
                </div>
            </div>
        </SelectionContext.Provider>
    );
}

/** Tiles of a fixed width that wrap as the screen allows, so the details panel always sits right next to them. */
export function TileGrid({ children }: { children: ReactNode }) {
    return <div className="flex flex-wrap items-start gap-x-3">{children}</div>;
}

/** One person in a list: just the name and the chat button. Clicking the name shows the details on the right. */
function PersonRow({ person }: { person: Person }) {
    const { person: selected, select } = useStructureSelection();
    const active = selected?.id === person.id;

    return (
        <div className={cn('flex items-center gap-2 rounded border px-3 py-2 text-sm transition-colors', active ? 'border-[#007bff] bg-[#e7f1ff]' : 'border-[#e9ecef] bg-white hover:bg-[#f8f9fa]')}>
            <button
                type="button"
                onClick={() => select(active ? null : person)}
                aria-pressed={active}
                title="Pokaż szczegóły"
                className="min-w-0 flex-1 truncate text-left font-semibold"
            >
                {person.name}
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

/** Contact details of a headquarters or a branch, with its barcode. */
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
            {unit.barcode && (
                <div className="flex justify-center pt-1">
                    <Barcode value={unit.barcode} format="EAN13" width={1.2} height={32} fontSize={11} margin={0} background="transparent" />
                </div>
            )}
        </div>
    );
}

/** One unit (the headquarters itself or a branch): a header that opens it, and its contact and people when open. */
function UnitSection({ title, kind, unit, open, onToggle }: { title: string; kind: string; unit: Unit; open: boolean; onToggle: () => void }) {
    return (
        <div className={cn('mb-2 rounded border transition-colors', open ? 'border-[#007bff] bg-white' : 'border-[#e9ecef] bg-[#f8f9fa]')}>
            <button type="button" onClick={onToggle} aria-expanded={open} className="flex w-full items-center gap-2 px-3 py-2 text-left">
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

/**
 * A headquarters tile: the header shows the organization and totals; below are the headquarters itself and each
 * branch as sections. Clicking a section opens it and collapses the others with an animation.
 */
export function HeadquartersTile({ headquarters }: { headquarters: Headquarters }) {
    const [active, setActive] = useState<string | null>(null);
    const toggle = (key: string) => setActive(active === key ? null : key);
    const peopleCount = headquarters.people.length + headquarters.branches.reduce((sum, branch) => sum + branch.people.length, 0);

    return (
        <div className="w-full pb-3 md:w-[24rem]">
            <section className="rounded border border-[#dee2e6] bg-white p-3 shadow-sm">
                <header className="flex items-start gap-2 pb-3">
                    <Building2 className="mt-0.5 size-5 shrink-0 text-[#007bff]" />
                    <div className="min-w-0">
                        <h5 className="font-semibold leading-tight">{headquarters.name}</h5>
                        {headquarters.organization && <p className="text-xs text-[#6c757d]">organizacja: {headquarters.organization}</p>}
                        <p className="text-xs text-[#6c757d]">
                            {headquarters.branches.length} placówek · {peopleCount} osób
                        </p>
                    </div>
                </header>

                <UnitSection title="Główna siedziba" kind="kontakt i kierownicy" unit={headquarters} open={active === 'hq'} onToggle={() => toggle('hq')} />

                {headquarters.branches.length === 0 && <p className="px-1 text-xs text-[#6c757d]">Brak placówek.</p>}
                {headquarters.branches.map((branch) => (
                    <UnitSection
                        key={branch.id}
                        title={branch.name}
                        kind="placówka"
                        unit={branch}
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
