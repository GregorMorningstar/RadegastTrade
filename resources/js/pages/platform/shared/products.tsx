import { Head, Link, router, usePage } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, KeyRound, Package, Search } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { FormEvent, ReactNode } from 'react';
import { Card, Content, ContentHeader } from '@/components/adminlte/blocks';
import { cn } from '@/lib/utils';
import { edit as editApiKey } from '@/routes/api-key';

type Category = { id: number; name: string; parent_id: number | null; count: number; total: number };

type Product = { id: number; name: string; barcode: string; description: string | null; price: number | null; category: string | null };

type Props = {
    ready: boolean;
    products: { data: Product[]; current_page: number; last_page: number; per_page: number; total: number };
    categories: Category[];
    total_products: number;
    filters: { category: number | null; q: string | null; sort: string };
};

const sorts = [
    { value: 'name', label: 'Nazwa A–Z' },
    { value: 'name_desc', label: 'Nazwa Z–A' },
    { value: 'newest', label: 'Najnowsze' },
];

/** Product catalogue (read only): categories on the left, search and sorting above the list. The same view for every role. */
export default function Products({ ready, products, categories, total_products, filters }: Props) {
    const { rolePanel, apiKeyAllowed } = usePage().props;
    const base = rolePanel?.base ?? '/';
    const url = `${base}/products`;
    const [search, setSearch] = useState(filters.q ?? '');

    useEffect(() => setSearch(filters.q ?? ''), [filters.q]);

    const visit = (next: Partial<{ category: number | null; q: string | null; sort: string; page: number }>) => {
        const query = { category: filters.category, q: filters.q, sort: filters.sort, ...next };

        router.get(
            url,
            Object.fromEntries(Object.entries(query).filter(([key, value]) => value !== null && value !== '' && !(key === 'sort' && value === 'name'))),
            { preserveState: true, preserveScroll: true, replace: true, only: ['products', 'categories', 'total_products', 'filters', 'ready'] },
        );
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        visit({ q: search.trim() || null, page: 1 });
    };

    return (
        <>
            <Head title="Produkty" />
            <ContentHeader title="Produkty" crumbs={[{ label: 'Panel', href: base }, { label: 'Produkty' }]} />
            <Content>
                <div className="grid gap-4 lg:grid-cols-[17rem_1fr]">
                    <Card title="Kategorie" color="primary" className="self-start">
                        <nav className="-mx-1 grid gap-0.5 text-sm">
                            <CategoryButton active={filters.category === null} onClick={() => visit({ category: null, page: 1 })} label="Wszystkie produkty" count={total_products} />
                            <CategoryTree categories={categories} parent={null} depth={0} active={filters.category} onSelect={(id) => visit({ category: id, page: 1 })} />
                        </nav>
                        {categories.length === 0 && <p className="mt-2 text-xs text-[#6c757d]">Nie ma jeszcze kategorii.</p>}
                    </Card>

                    <Card color="primary">
                        <form onSubmit={submit} className="mb-4 flex flex-wrap items-center gap-2">
                            <div className="relative min-w-56 flex-1">
                                <Search className="absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-[#6c757d]" />
                                <input
                                    value={search}
                                    onChange={(event) => setSearch(event.target.value)}
                                    placeholder="Szukaj po nazwie lub kodzie kreskowym…"
                                    maxLength={100}
                                    className="w-full rounded border border-[#ced4da] py-1.5 pr-2 pl-8 text-sm focus:border-[#80bdff] focus:outline-none"
                                />
                            </div>
                            <select value={filters.sort} onChange={(event) => visit({ sort: event.target.value, page: 1 })} className="rounded border border-[#ced4da] px-2 py-1.5 text-sm" aria-label="Sortowanie">
                                {sorts.map((item) => (
                                    <option key={item.value} value={item.value}>
                                        {item.label}
                                    </option>
                                ))}
                            </select>
                            <button type="submit" className="rounded bg-[#007bff] px-3 py-1.5 text-sm text-white">
                                Szukaj
                            </button>
                            {(filters.q || filters.category !== null) && (
                                <button type="button" onClick={() => visit({ q: null, category: null, page: 1 })} className="rounded border border-[#ced4da] px-3 py-1.5 text-sm">
                                    Wyczyść
                                </button>
                            )}
                        </form>

                        {!ready ? (
                            <Empty text="Katalog produktów nie jest jeszcze skonfigurowany.">
                                {apiKeyAllowed ? (
                                    <Link href={editApiKey().url} className="mt-1 flex items-center gap-1.5 rounded bg-[#007bff] px-3 py-1.5 text-white">
                                        <KeyRound className="size-4" />
                                        Skonfiguruj konektor: klucz API jednostki
                                    </Link>
                                ) : (
                                    <span className="text-xs">Poproś właściciela lub kierownika o wygenerowanie klucza API dla Twojej jednostki.</span>
                                )}
                            </Empty>
                        ) : products.data.length === 0 ? (
                            <Empty text={filters.q || filters.category !== null ? 'Brak produktów dla wybranych filtrów.' : 'Nie ma jeszcze żadnych produktów.'} />
                        ) : (
                            <>
                                <p className="mb-2 text-xs text-[#6c757d]">Znaleziono: {products.total}</p>
                                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                                    {products.data.map((product) => (
                                        <article key={product.id} className="flex flex-col gap-1 rounded border border-[#dee2e6] p-3">
                                            <div className="flex items-start justify-between gap-2">
                                                <h4 className="text-sm font-semibold">{product.name}</h4>
                                                {product.price !== null && <span className="text-sm font-semibold whitespace-nowrap">{product.price.toFixed(2)} zł</span>}
                                            </div>
                                            {product.category && <span className="w-fit rounded bg-[#e9ecef] px-1.5 py-0.5 text-xs text-[#495057]">{product.category}</span>}
                                            {product.description && <p className="line-clamp-2 text-xs text-[#6c757d]">{product.description}</p>}
                                            <p className="mt-auto pt-1 font-mono text-xs text-[#6c757d]">{product.barcode}</p>
                                        </article>
                                    ))}
                                </div>

                                {products.last_page > 1 && (
                                    <div className="mt-4 flex items-center justify-between text-sm">
                                        <button type="button" disabled={products.current_page <= 1} onClick={() => visit({ page: products.current_page - 1 })} className="flex items-center gap-1 rounded border border-[#ced4da] px-3 py-1.5 disabled:opacity-40">
                                            <ChevronLeft className="size-4" />
                                            Poprzednia
                                        </button>
                                        <span className="text-[#6c757d]">
                                            Strona {products.current_page} z {products.last_page}
                                        </span>
                                        <button type="button" disabled={products.current_page >= products.last_page} onClick={() => visit({ page: products.current_page + 1 })} className="flex items-center gap-1 rounded border border-[#ced4da] px-3 py-1.5 disabled:opacity-40">
                                            Następna
                                            <ChevronRight className="size-4" />
                                        </button>
                                    </div>
                                )}
                            </>
                        )}
                    </Card>
                </div>
            </Content>
        </>
    );
}

function Empty({ text, children }: { text: string; children?: ReactNode }) {
    return (
        <div className="flex flex-col items-center gap-2 py-10 text-center text-sm text-[#6c757d]">
            <Package className="size-8" />
            {text}
            {children}
        </div>
    );
}

function CategoryButton({ label, count, active, depth = 0, onClick }: { label: string; count: number; active: boolean; depth?: number; onClick: () => void }) {
    return (
        <button
            type="button"
            onClick={onClick}
            style={{ paddingLeft: `${0.5 + depth * 0.9}rem` }}
            className={cn('flex w-full items-center justify-between gap-2 rounded py-1.5 pr-2 text-left hover:bg-[#f1f3f5]', active && 'bg-[#007bff] text-white hover:bg-[#007bff]')}
        >
            <span className="truncate">{label}</span>
            <span className={cn('rounded-full px-1.5 text-xs', active ? 'bg-white/25' : 'bg-[#e9ecef] text-[#495057]')}>{count}</span>
        </button>
    );
}

function CategoryTree({ categories, parent, depth, active, onSelect }: { categories: Category[]; parent: number | null; depth: number; active: number | null; onSelect: (id: number) => void }) {
    return (
        <>
            {categories
                .filter((category) => category.parent_id === parent)
                .map((category) => (
                    <div key={category.id}>
                        <CategoryButton label={category.name} count={category.total} active={active === category.id} depth={depth + 1} onClick={() => onSelect(category.id)} />
                        <CategoryTree categories={categories} parent={category.id} depth={depth + 1} active={active} onSelect={onSelect} />
                    </div>
                ))}
        </>
    );
}
