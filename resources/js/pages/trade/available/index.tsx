import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { Card, Content, ContentHeader } from '@/components/adminlte/blocks';
import { btn, btnOutline, date, Empty, Errors, inputCls, td, th, TradeTabs } from '@/components/trade/ui';

type Offer = {
    id: number;
    number: string;
    title: string | null;
    supplier: string;
    supplier_id: number;
    unit: string;
    unit_ref: string;
    currency: string | null;
    valid_to: string | null;
    shared_internally: boolean;
};

type Props = {
    offers: Offer[];
    suppliers: { id: number; name: string }[];
    units: { ref: string; name: string; organization_id: number }[];
    filters: { supplier?: string | number; unit?: string; q?: string };
};

export default function AvailableIndex({ offers, suppliers, units, filters }: Props) {
    const [supplier, setSupplier] = useState(String(filters.supplier ?? ''));
    const [unit, setUnit] = useState(filters.unit ?? '');
    const [q, setQ] = useState(filters.q ?? '');

    const apply = () => router.get('/trade/available', { supplier: supplier || undefined, unit: unit || undefined, q: q || undefined }, { preserveState: true });

    return (
        <>
            <Head title="Oferty dostępne" />
            <ContentHeader title="Oferty dostępne" crumbs={[{ label: 'Handel', href: '/trade/available' }, { label: 'Oferty dostępne' }]} />
            <Content>
                <TradeTabs current="available" />
                <Errors />
                <Card title="Oferty dla Twoich jednostek" color="primary">
                    <form
                        className="mb-4 grid gap-2 md:grid-cols-4"
                        onSubmit={(e) => {
                            e.preventDefault();
                            apply();
                        }}
                    >
                        <select className={inputCls} value={supplier} onChange={(e) => setSupplier(e.target.value)} aria-label="Dostawca">
                            <option value="">Wszyscy dostawcy</option>
                            {suppliers.map((s) => (
                                <option key={s.id} value={s.id}>
                                    {s.name}
                                </option>
                            ))}
                        </select>
                        <select className={inputCls} value={unit} onChange={(e) => setUnit(e.target.value)} aria-label="Jednostka dostawcy">
                            <option value="">Wszystkie jednostki dostawcy</option>
                            {units
                                .filter((u) => !supplier || String(u.organization_id) === supplier)
                                .map((u) => (
                                    <option key={u.ref} value={u.ref}>
                                        {u.name}
                                    </option>
                                ))}
                        </select>
                        <input className={inputCls} placeholder="Szukaj w tytule" value={q} onChange={(e) => setQ(e.target.value)} />
                        <div className="flex gap-2">
                            <button type="submit" className={btn}>
                                Filtruj
                            </button>
                            <Link href="/trade/available" className={btnOutline}>
                                Wyczyść
                            </Link>
                        </div>
                    </form>

                    {offers.length === 0 ? (
                        <Empty>Nie ma teraz ofert dostępnych dla Twoich jednostek.</Empty>
                    ) : (
                        <table className="w-full text-left text-sm">
                            <thead>
                                <tr className="border-b border-[#dee2e6]">
                                    <th className={th}>Numer</th>
                                    <th className={th}>Tytuł</th>
                                    <th className={th}>Dostawca</th>
                                    <th className={th}>Ważna do</th>
                                    <th className={th}>Źródło</th>
                                </tr>
                            </thead>
                            <tbody>
                                {offers.map((offer) => (
                                    <tr key={offer.id} className="border-b border-[#dee2e6] last:border-0">
                                        <td className={td}>
                                            <Link href={`/trade/available/${offer.id}`} className="text-[#007bff] hover:underline">
                                                {offer.number}
                                            </Link>
                                        </td>
                                        <td className={td}>{offer.title}</td>
                                        <td className={td}>
                                            {offer.supplier} – {offer.unit}
                                        </td>
                                        <td className={td}>{date(offer.valid_to)}</td>
                                        <td className={td}>{offer.shared_internally ? 'w organizacji' : 'od kontrahenta'}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </Card>
            </Content>
        </>
    );
}
