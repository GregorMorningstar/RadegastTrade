import { ChevronDown, Store, Warehouse } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { Collapse, HeadquartersTile, Pager, PeopleTiles, SearchBox, StructureLayout, TileGrid } from '@/pages/platform/admin/components/StructureTiles';
import type { Headquarters, Paging, Person } from '@/pages/platform/admin/components/StructureTiles';

type Structure = {
    owners: Person[];
    headquarters: Paging<Headquarters>;
};

type Type = 'shops' | 'warehouses';

const TABS = [
    { value: 'shops', label: 'Sklepy', Icon: Store },
    { value: 'warehouses', label: 'Hurtownie', Icon: Warehouse },
] as const;

/**
 * Detailed breakdown of an organization: its owners (collapsed by default), then (per shops / warehouses, paged)
 * every headquarters with its contact, people and branches with the people working in them.
 * People are listed by name with a chat button; the details of a clicked person appear on the right.
 * The data comes from the members endpoint (administrator, manager and employee only).
 */
export default function OrganizationStructure({ organizationId }: { organizationId: number }) {
    const [type, setType] = useState<Type>('shops');
    const [page, setPage] = useState(1);
    const [structure, setStructure] = useState<Structure | null>(null);
    const [error, setError] = useState(false);
    const [search, setSearch] = useState('');
    // organization data (owners) stays collapsed while browsing shops and warehouses
    const [ownersOpen, setOwnersOpen] = useState(false);

    const load = useCallback(async () => {
        const query = new URLSearchParams({ type, page: String(page), ...(search ? { q: search } : {}) });

        try {
            const response = await fetch(`/platform/organizations/${organizationId}/members?${query}`, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });

            if (!response.ok) {
                throw new Error(String(response.status));
            }

            setStructure((await response.json()) as Structure);
            setError(false);
        } catch {
            setError(true);
        }
    }, [organizationId, type, page, search]);

    useEffect(() => {
        void load();
    }, [load]);

    const changeSearch = useCallback((text: string) => {
        setSearch(text);
        setPage(1);
    }, []);

    const changeType = (value: Type) => {
        setType(value);
        setPage(1);
    };

    const paging = structure?.headquarters;

    return (
        <StructureLayout>
            {error && <p className="text-sm text-[#dc3545]">Nie udało się pobrać osób.</p>}
            {!error && structure === null && <p className="text-sm text-[#6c757d]">Ładowanie…</p>}

            {structure && (
                <section className="pb-4">
                    <button
                        type="button"
                        onClick={() => setOwnersOpen(!ownersOpen)}
                        aria-expanded={ownersOpen}
                        className="mb-2 flex items-center gap-2 text-sm font-semibold"
                    >
                        <ChevronDown className={`size-4 text-[#6c757d] transition-transform duration-300 ${ownersOpen ? '' : '-rotate-90'}`} />
                        Właściciele organizacji
                        <span className="text-xs font-normal text-[#6c757d]">({structure.owners.length})</span>
                    </button>
                    <Collapse open={ownersOpen}>
                        <div className="max-w-md">
                            <PeopleTiles people={structure.owners} empty="Brak właścicieli." />
                        </div>
                    </Collapse>
                </section>
            )}

            <div className="mb-4 flex gap-1 border-b border-[#dee2e6]">
                {TABS.map(({ value, label, Icon }) => (
                    <button
                        key={value}
                        type="button"
                        onClick={() => changeType(value)}
                        className={`flex items-center gap-1.5 border-b-2 px-3 py-2 text-sm ${type === value ? 'border-[#007bff] text-[#007bff]' : 'border-transparent text-[#6c757d]'}`}
                    >
                        <Icon className="size-4" />
                        {label}
                    </button>
                ))}
            </div>

            <SearchBox onSearch={changeSearch} />

            {paging && paging.data.length === 0 && (
                <p className="text-sm text-[#6c757d]">
                    {search ? 'Nic nie znaleziono.' : type === 'shops' ? 'Brak siedzib sklepów.' : 'Brak siedzib hurtowni.'}
                </p>
            )}

            {paging && paging.data.length > 0 && (
                <TileGrid>
                    {paging.data.map((headquarters) => (
                        <HeadquartersTile key={headquarters.id} headquarters={headquarters} />
                    ))}
                </TileGrid>
            )}

            {paging && <Pager paging={paging} onPage={setPage} />}
        </StructureLayout>
    );
}
