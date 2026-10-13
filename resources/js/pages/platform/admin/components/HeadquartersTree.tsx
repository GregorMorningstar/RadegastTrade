import { useCallback, useEffect, useState } from 'react';
import { HeadquartersTile, Pager, SearchBox, StructureLayout, TileGrid } from '@/pages/platform/admin/components/StructureTiles';
import type { Headquarters, Paging } from '@/pages/platform/admin/components/StructureTiles';

/**
 * All shop or warehouse headquarters (from every organization) as tiles, up to 3 in a row: the organization,
 * the headquarters with its contact and people, and its branches with their people, each with a chat action.
 * People are listed by name with a chat button; the details of a clicked person appear on the right.
 * The data comes from the tree endpoint (administrator, manager and employee only).
 */
export default function HeadquartersTree({ type }: { type: 'shops' | 'warehouses' }) {
    const [page, setPage] = useState(1);
    const [result, setResult] = useState<Paging<Headquarters> | null>(null);
    const [error, setError] = useState(false);
    const [search, setSearch] = useState('');

    const load = useCallback(async () => {
        try {
            const response = await fetch(`/platform/headquarters/${type}/tree?${new URLSearchParams({ page: String(page), ...(search ? { q: search } : {}) })}`, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });

            if (!response.ok) {
                throw new Error(String(response.status));
            }

            setResult((await response.json()) as Paging<Headquarters>);
            setError(false);
        } catch {
            setError(true);
        }
    }, [type, page, search]);

    useEffect(() => {
        void load();
    }, [load]);

    const changeSearch = useCallback((text: string) => {
        setSearch(text);
        setPage(1);
    }, []);

    return (
        <StructureLayout>
            <SearchBox onSearch={changeSearch} />

            {error && <p className="text-sm text-[#dc3545]">Nie udało się pobrać danych.</p>}
            {!error && result === null && <p className="text-sm text-[#6c757d]">Ładowanie…</p>}
            {result && result.data.length === 0 && (
                <p className="text-sm text-[#6c757d]">
                    {search ? 'Nic nie znaleziono.' : type === 'shops' ? 'Brak siedzib sklepów.' : 'Brak siedzib hurtowni.'}
                </p>
            )}

            {result && result.data.length > 0 && (
                <TileGrid>
                    {result.data.map((headquarters) => (
                        <HeadquartersTile key={headquarters.id} headquarters={headquarters} />
                    ))}
                </TileGrid>
            )}

            {result && <Pager paging={result} onPage={setPage} />}
        </StructureLayout>
    );
}
