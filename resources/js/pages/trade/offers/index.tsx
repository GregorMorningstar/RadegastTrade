import { Head, Link } from '@inertiajs/react';
import { Card, Content, ContentHeader } from '@/components/adminlte/blocks';
import { Badge, btn, date, Empty, Errors, td, th, TradeTabs } from '@/components/trade/ui';

type OfferRow = {
    id: number;
    number: string;
    title: string | null;
    status: string;
    unit: string;
    valid_to: string | null;
    version_no: number | null;
    shared_internally: boolean;
    has_draft: boolean;
};

const filters: [string | null, string][] = [
    [null, 'Wszystkie'],
    ['draft', 'Szkice'],
    ['published', 'Opublikowane'],
    ['expired', 'Wygasłe'],
    ['withdrawn', 'Wycofane'],
    ['archived', 'Zarchiwizowane'],
];

export default function OffersIndex({ offers, status, canCreate }: { offers: OfferRow[]; status: string | null; canCreate: boolean }) {
    return (
        <>
            <Head title="Moje oferty" />
            <ContentHeader title="Oferty handlowe" crumbs={[{ label: 'Handel', href: '/trade/available' }, { label: 'Moje oferty' }]} />
            <Content>
                <TradeTabs current="offers" />
                <Errors />
                <Card title="Moje oferty" color="primary">
                    <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                        <div className="flex flex-wrap gap-1 text-sm">
                            {filters.map(([key, label]) => (
                                <Link
                                    key={label}
                                    href={key ? `/trade/offers?status=${key}` : '/trade/offers'}
                                    className={`rounded px-2 py-1 ${status === key ? 'bg-[#007bff] text-white' : 'text-[#007bff] hover:bg-[#e9ecef]'}`}
                                >
                                    {label}
                                </Link>
                            ))}
                        </div>
                        {canCreate && (
                            <Link href="/trade/offers/create" className={btn}>
                                Nowa oferta
                            </Link>
                        )}
                    </div>

                    {offers.length === 0 ? (
                        <Empty>Brak ofert w tym widoku. Oferty przedstawiają hurtownie, którymi zarządzasz.</Empty>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-sm">
                                <thead>
                                    <tr className="border-b border-[#dee2e6]">
                                        <th className={th}>Numer</th>
                                        <th className={th}>Tytuł</th>
                                        <th className={th}>Hurtownia</th>
                                        <th className={th}>Status</th>
                                        <th className={th}>Ważna do</th>
                                        <th className={th}>Wersja</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {offers.map((offer) => (
                                        <tr key={offer.id} className="border-b border-[#dee2e6] last:border-0">
                                            <td className={td}>
                                                <Link href={`/trade/offers/${offer.id}`} className="text-[#007bff] hover:underline">
                                                    {offer.number}
                                                </Link>
                                            </td>
                                            <td className={td}>{offer.title}</td>
                                            <td className={td}>{offer.unit}</td>
                                            <td className={td}>
                                                <Badge status={offer.status} />
                                                {offer.shared_internally && <span className="ml-1 text-xs text-[#6c757d]">w organizacji</span>}
                                                {offer.has_draft && offer.status !== 'draft' && <span className="ml-1 text-xs text-[#6c757d]">(jest szkic nowej wersji)</span>}
                                            </td>
                                            <td className={td}>{date(offer.valid_to)}</td>
                                            <td className={td}>{offer.version_no ?? '—'}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </Card>
            </Content>
        </>
    );
}
