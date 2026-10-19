import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Card, Content, ContentHeader } from '@/components/adminlte/blocks';
import { Badge, btn, btnDanger, btnOutline, date, Empty, Errors, money, td, th, TradeTabs } from '@/components/trade/ui';

type Item = {
    id: number;
    sku: string | null;
    name: string;
    unit_of_measure: string;
    unit_price: string;
    vat_rate: string | null;
    min_qty: string;
    qty_step: string;
    max_qty: string | null;
};

type Version = {
    id: number;
    version_no: number;
    title: string;
    description: string | null;
    currency: string;
    valid_from: string | null;
    valid_to: string | null;
    delivery_terms: string | null;
    items: Item[];
    attachments: { id: number; name: string; size: number }[];
};

type Offer = {
    id: number;
    number: string;
    title: string | null;
    status: string;
    unit: string;
    supplier: string;
    shared_internally: boolean;
    is_seller: boolean;
    can_edit?: boolean;
    version: Version | null;
    draft?: Version | null;
    versions?: { id: number; version_no: number; status: string; published_at: string | null }[];
    shares?: { id: number; unit: string; organization: string; allow_order: boolean; shared_at: string; revoked_at: string | null }[];
    recipient_units?: { ref: string; name: string; organization: string }[];
};

function post(url: string, data: Record<string, unknown> = {}) {
    router.post(url, data as never, { preserveScroll: true });
}

function VersionCard({ title, version }: { title: string; version: Version }) {
    return (
        <Card title={title} color="info">
            {version.description && <p className="mb-2 text-sm">{version.description}</p>}
            <p className="mb-3 text-sm text-[#6c757d]">
                Obowiązuje: {date(version.valid_from)} – {date(version.valid_to)}. {version.delivery_terms && <>Dostawa: {version.delivery_terms}</>}
            </p>
            <div className="overflow-x-auto">
                <table className="w-full text-left text-sm">
                    <thead>
                        <tr className="border-b border-[#dee2e6]">
                            <th className={th}>Produkt</th>
                            <th className={th}>SKU</th>
                            <th className={th}>Cena netto</th>
                            <th className={th}>VAT</th>
                            <th className={th}>Min.</th>
                            <th className={th}>Wielokrotność</th>
                            <th className={th}>Maks.</th>
                        </tr>
                    </thead>
                    <tbody>
                        {version.items.map((item) => (
                            <tr key={item.id} className="border-b border-[#dee2e6] last:border-0">
                                <td className={td}>{item.name}</td>
                                <td className={td}>{item.sku ?? '—'}</td>
                                <td className={td}>
                                    {money(item.unit_price, version.currency)} / {item.unit_of_measure}
                                </td>
                                <td className={td}>{item.vat_rate === null ? '—' : `${Number(item.vat_rate)}%`}</td>
                                <td className={td}>{Number(item.min_qty)}</td>
                                <td className={td}>{Number(item.qty_step)}</td>
                                <td className={td}>{item.max_qty === null ? '—' : Number(item.max_qty)}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </Card>
    );
}

function Attachments({ offer, version, editable }: { offer: Offer; version: Version; editable: boolean }) {
    const form = useForm<{ file: File | null }>({ file: null });

    return (
        <Card title="Załączniki">
            {version.attachments.length === 0 && <Empty>Brak załączników.</Empty>}
            <ul className="mb-3 space-y-1 text-sm">
                {version.attachments.map((attachment) => (
                    <li key={attachment.id} className="flex items-center gap-3">
                        <a className="text-[#007bff] hover:underline" href={`/trade/attachments/${attachment.id}`}>
                            {attachment.name}
                        </a>
                        <span className="text-xs text-[#6c757d]">{Math.round(attachment.size / 1024)} KB</span>
                        {editable && (
                            <button className="text-xs text-[#dc3545]" onClick={() => router.delete(`/trade/offers/${offer.id}/attachments/${attachment.id}`, { preserveScroll: true })}>
                                usuń
                            </button>
                        )}
                    </li>
                ))}
            </ul>
            {editable && (
                <form
                    className="flex flex-wrap items-center gap-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(`/trade/offers/${offer.id}/attachments`, { forceFormData: true, preserveScroll: true, onSuccess: () => form.reset() });
                    }}
                >
                    <input type="file" accept=".pdf,.jpg,.jpeg,.png,.xlsx,.docx" onChange={(e) => form.setData('file', e.target.files?.[0] ?? null)} className="text-sm" />
                    <button type="submit" className={btnOutline} disabled={!form.data.file || form.processing}>
                        Dodaj załącznik
                    </button>
                    <span className="text-xs text-[#6c757d]">PDF, JPG, PNG, XLSX, DOCX, do 5 MB (trafia do wersji roboczej).</span>
                </form>
            )}
        </Card>
    );
}

function SellerPanel({ offer }: { offer: Offer }) {
    const [internal, setInternal] = useState(offer.shared_internally);
    const [units, setUnits] = useState<string[]>([]);
    const [allowOrder, setAllowOrder] = useState(true);
    const activeShares = (offer.shares ?? []).filter((share) => share.revoked_at === null);
    const sharedRefs = new Set(activeShares.map((share) => `${share.organization}|${share.unit}`));
    const canShare = offer.status === 'published';

    return (
        <>
            <Card title="Działania" color="primary">
                <div className="flex flex-wrap items-center gap-2">
                    {offer.can_edit && (
                        <Link href={`/trade/offers/${offer.id}/edit`} className={btnOutline}>
                            {offer.draft ? 'Edytuj szkic' : 'Nowa wersja (zmień warunki)'}
                        </Link>
                    )}
                    {offer.draft && (
                        <button className={btn} onClick={() => post(`/trade/offers/${offer.id}/publish`, { shared_internally: internal })}>
                            Opublikuj wersję {offer.draft.version_no}
                        </button>
                    )}
                    {offer.status === 'published' && (
                        <button className={btnDanger} onClick={() => confirm('Wycofać ofertę? Nowe zamówienia będą zablokowane.') && post(`/trade/offers/${offer.id}/withdraw`)}>
                            Wycofaj
                        </button>
                    )}
                    {['withdrawn', 'expired', 'draft'].includes(offer.status) && (
                        <button className={btnOutline} onClick={() => confirm('Zarchiwizować ofertę?') && post(`/trade/offers/${offer.id}/archive`)}>
                            Archiwizuj
                        </button>
                    )}
                </div>
                <label className="mt-3 flex items-center gap-2 text-sm">
                    <input
                        type="checkbox"
                        checked={internal}
                        onChange={(e) => {
                            setInternal(e.target.checked);

                            if (offer.status !== 'draft') {
                                post(`/trade/offers/${offer.id}/internal`, { shared_internally: e.target.checked });
                            }
                        }}
                    />
                    Pokaż ofertę jednostkom mojej organizacji (po publikacji). Daje wgląd tylko w samą ofertę.
                </label>
            </Card>

            {offer.draft && <VersionCard title={`Szkic wersji ${offer.draft.version_no} (niepublikowany)`} version={offer.draft} />}
            {offer.draft && <Attachments offer={offer} version={offer.draft} editable />}

            <Card title="Udostępnianie kontrahentom" color="success">
                {!canShare && <Empty>Udostępniać można tylko opublikowaną ofertę.</Empty>}
                {canShare && (offer.recipient_units ?? []).length === 0 && (
                    <Empty>Brak jednostek w zakresie aktywnej współpracy. Zaproś kontrahenta w zakładce „Współpraca”.</Empty>
                )}
                {canShare && (offer.recipient_units ?? []).length > 0 && (
                    <div className="space-y-2 text-sm">
                        {(offer.recipient_units ?? []).map((unit) => (
                            <label key={unit.ref} className="flex items-center gap-2">
                                <input
                                    type="checkbox"
                                    checked={units.includes(unit.ref)}
                                    onChange={(e) => setUnits(e.target.checked ? [...units, unit.ref] : units.filter((ref) => ref !== unit.ref))}
                                />
                                {unit.organization} – {unit.name}
                                {sharedRefs.has(`${unit.organization}|${unit.name}`) && <span className="text-xs text-[#28a745]">(już udostępniono)</span>}
                            </label>
                        ))}
                        <label className="flex items-center gap-2">
                            <input type="checkbox" checked={allowOrder} onChange={(e) => setAllowOrder(e.target.checked)} />
                            Odbiorca może składać zamówienia (bez tego tylko ogląda)
                        </label>
                        <button className={btn} disabled={units.length === 0} onClick={() => post(`/trade/offers/${offer.id}/shares`, { units, allow_order: allowOrder })}>
                            Udostępnij
                        </button>
                    </div>
                )}

                <h4 className="mt-4 mb-2 text-sm font-semibold">Historia udostępnień</h4>
                {(offer.shares ?? []).length === 0 ? (
                    <Empty>Oferta nie była udostępniana na zewnątrz.</Empty>
                ) : (
                    <table className="w-full text-left text-sm">
                        <thead>
                            <tr className="border-b border-[#dee2e6]">
                                <th className={th}>Odbiorca</th>
                                <th className={th}>Zamawianie</th>
                                <th className={th}>Od</th>
                                <th className={th}>Cofnięto</th>
                                <th className={th}></th>
                            </tr>
                        </thead>
                        <tbody>
                            {(offer.shares ?? []).map((share) => (
                                <tr key={share.id} className="border-b border-[#dee2e6] last:border-0">
                                    <td className={td}>
                                        {share.organization} – {share.unit}
                                    </td>
                                    <td className={td}>{share.allow_order ? 'tak' : 'tylko podgląd'}</td>
                                    <td className={td}>{date(share.shared_at)}</td>
                                    <td className={td}>{date(share.revoked_at)}</td>
                                    <td className={td}>
                                        {share.revoked_at === null && (
                                            <button className="text-xs text-[#dc3545]" onClick={() => router.delete(`/trade/offers/${offer.id}/shares/${share.id}`, { preserveScroll: true })}>
                                                cofnij
                                            </button>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </Card>

            <Card title="Wersje">
                <ul className="text-sm">
                    {(offer.versions ?? []).map((version) => (
                        <li key={version.id}>
                            Wersja {version.version_no}: {version.status === 'draft' ? 'szkic' : version.status === 'published' ? 'obowiązująca' : 'zastąpiona'}
                            {version.published_at && <> (opublikowana {date(version.published_at)})</>}
                        </li>
                    ))}
                </ul>
            </Card>
        </>
    );
}

export default function OfferShow({ offer }: { offer: Offer }) {
    return (
        <>
            <Head title={offer.number} />
            <ContentHeader
                title={`Oferta ${offer.number}`}
                crumbs={[{ label: 'Handel', href: '/trade/available' }, { label: 'Moje oferty', href: '/trade/offers' }, { label: offer.number }]}
            />
            <Content>
                <TradeTabs current={offer.is_seller ? 'offers' : 'available'} />
                <Errors />
                <div className="space-y-4">
                    <Card title={offer.title ?? offer.number} color="primary">
                        <p className="text-sm">
                            <Badge status={offer.status} /> {offer.supplier} – {offer.unit}
                        </p>
                    </Card>

                    {offer.is_seller && <SellerPanel offer={offer} />}

                    {offer.version ? (
                        <>
                            <VersionCard title={`Obowiązująca wersja ${offer.version.version_no}`} version={offer.version} />
                            <Attachments offer={offer} version={offer.version} editable={false} />
                        </>
                    ) : (
                        <Card>
                            <Empty>Oferta nie ma jeszcze opublikowanej wersji.</Empty>
                        </Card>
                    )}

                    {!offer.is_seller && (
                        <Link href={`/trade/available/${offer.id}`} className={btn}>
                            Przejdź do zamawiania
                        </Link>
                    )}
                </div>
            </Content>
        </>
    );
}
