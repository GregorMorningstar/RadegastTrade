import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Card, Content, ContentHeader } from '@/components/adminlte/blocks';
import { Badge, btn, btnOutline, date, Empty, Errors, inputCls, money, td, th, TradeTabs } from '@/components/trade/ui';

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

type Offer = {
    id: number;
    number: string;
    title: string | null;
    status: string;
    supplier: string;
    unit: string;
    is_seller: boolean;
    version: {
        id: number;
        version_no: number;
        description: string | null;
        currency: string;
        valid_from: string | null;
        valid_to: string | null;
        delivery_terms: string | null;
        items: Item[];
        attachments: { id: number; name: string; size: number }[];
    } | null;
    order_units: { ref: string; name: string }[];
};

/**
 * An offer for a buyer: the published version, and the order form for the units the person may order for. The prices shown
 * here are for looking only; the server takes the prices from the version and ignores any price sent with the form.
 */
export default function AvailableShow({ offer, idempotencyKey }: { offer: Offer; idempotencyKey: string }) {
    const version = offer.version;
    const form = useForm({
        offer_id: offer.id,
        offer_version_id: version?.id ?? 0,
        buyer_unit: offer.order_units[0]?.ref ?? '',
        items: (version?.items ?? []).map((item) => ({ offer_item_id: item.id, qty: '' })),
        delivery_address: { name: '', street: '', postal_code: '', city: '', country: 'Polska', phone: '' },
        notes: '',
        idempotency_key: idempotencyKey,
    });

    const lines = form.data.items.filter((item) => Number(item.qty) > 0);
    const estimate = lines.reduce((sum, line) => {
        const item = version?.items.find((candidate) => candidate.id === line.offer_item_id);

        return item ? sum + Number(item.unit_price) * Number(line.qty) * (1 + Number(item.vat_rate ?? 0) / 100) : sum;
    }, 0);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({ ...data, items: data.items.filter((item) => Number(item.qty) > 0) }));
        form.post('/trade/orders');
    };

    const address = (field: keyof typeof form.data.delivery_address, value: string) => form.setData('delivery_address', { ...form.data.delivery_address, [field]: value });

    return (
        <>
            <Head title={offer.number} />
            <ContentHeader
                title={`Oferta ${offer.number}`}
                crumbs={[{ label: 'Handel', href: '/trade/available' }, { label: 'Oferty dostępne', href: '/trade/available' }, { label: offer.number }]}
            />
            <Content>
                <TradeTabs current="available" />
                <Errors />
                <div className="space-y-4">
                    <Card title={offer.title ?? offer.number} color="primary">
                        <p className="text-sm">
                            <Badge status={offer.status} /> {offer.supplier} – {offer.unit}
                        </p>
                        {version && (
                            <p className="mt-2 text-sm text-[#6c757d]">
                                Wersja {version.version_no}. Obowiązuje: {date(version.valid_from)} – {date(version.valid_to)}.{' '}
                                {version.delivery_terms && <>Dostawa: {version.delivery_terms}</>}
                            </p>
                        )}
                        {version?.description && <p className="mt-2 text-sm">{version.description}</p>}
                        {version && version.attachments.length > 0 && (
                            <ul className="mt-2 text-sm">
                                {version.attachments.map((attachment) => (
                                    <li key={attachment.id}>
                                        <a className="text-[#007bff] hover:underline" href={`/trade/attachments/${attachment.id}`}>
                                            {attachment.name}
                                        </a>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>

                    {!version ? (
                        <Card>
                            <Empty>Ta oferta nie ma obowiązującej wersji.</Empty>
                        </Card>
                    ) : offer.order_units.length === 0 ? (
                        <>
                            <Card title="Pozycje" color="info">
                                <table className="w-full text-left text-sm">
                                    <tbody>
                                        {version.items.map((item) => (
                                            <tr key={item.id} className="border-b border-[#dee2e6] last:border-0">
                                                <td className={td}>{item.name}</td>
                                                <td className={td}>
                                                    {money(item.unit_price, version.currency)} / {item.unit_of_measure}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </Card>
                            <Card>
                                <p className="text-sm text-[#6c757d]">
                                    Możesz oglądać tę ofertę, ale nie możesz z niej zamawiać: oferta nie dopuszcza zamawiania dla Twojej jednostki, współpraca jest
                                    zawieszona albo nie masz uprawnienia do zamawiania dla jednostki. Uprawnienie nadaje kierownik jednostki.
                                </p>
                            </Card>
                        </>
                    ) : (
                        <form onSubmit={submit} className="space-y-4">
                            <Card title="Zamówienie" color="success">
                                <label className="mb-3 block text-sm">
                                    Zamawia jednostka
                                    <select className={inputCls} value={form.data.buyer_unit} onChange={(e) => form.setData('buyer_unit', e.target.value)}>
                                        {offer.order_units.map((unit) => (
                                            <option key={unit.ref} value={unit.ref}>
                                                {unit.name}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                                <table className="w-full text-left text-sm">
                                    <thead>
                                        <tr className="border-b border-[#dee2e6]">
                                            <th className={th}>Produkt</th>
                                            <th className={th}>Cena netto</th>
                                            <th className={th}>VAT</th>
                                            <th className={th}>Zasady ilości</th>
                                            <th className={th}>Ilość</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {version.items.map((item, index) => (
                                            <tr key={item.id} className="border-b border-[#dee2e6] last:border-0">
                                                <td className={td}>
                                                    {item.name} {item.sku && <span className="text-xs text-[#6c757d]">({item.sku})</span>}
                                                </td>
                                                <td className={td}>
                                                    {money(item.unit_price, version.currency)} / {item.unit_of_measure}
                                                </td>
                                                <td className={td}>{item.vat_rate === null ? '—' : `${Number(item.vat_rate)}%`}</td>
                                                <td className={td}>
                                                    min {Number(item.min_qty)}, krok {Number(item.qty_step)}
                                                    {item.max_qty !== null && <>, maks {Number(item.max_qty)}</>}
                                                </td>
                                                <td className={td}>
                                                    <input
                                                        className="w-24 rounded border border-[#ced4da] px-2 py-1 text-sm"
                                                        type="number"
                                                        min="0"
                                                        step="0.001"
                                                        value={form.data.items[index].qty}
                                                        onChange={(e) => form.setData('items', form.data.items.map((row, i) => (i === index ? { ...row, qty: e.target.value } : row)))}
                                                    />
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                                <p className="mt-3 text-sm">
                                    Szacunkowa wartość brutto: <strong>{money(estimate, version.currency)}</strong>{' '}
                                    <span className="text-xs text-[#6c757d]">(ostateczne kwoty liczy serwer z ceną z oferty)</span>
                                </p>
                            </Card>

                            <Card title="Adres dostawy">
                                <div className="grid gap-3 md:grid-cols-2">
                                    <label className="text-sm">
                                        Odbiorca
                                        <input className={inputCls} value={form.data.delivery_address.name} onChange={(e) => address('name', e.target.value)} required />
                                    </label>
                                    <label className="text-sm">
                                        Ulica i numer
                                        <input className={inputCls} value={form.data.delivery_address.street} onChange={(e) => address('street', e.target.value)} required />
                                    </label>
                                    <label className="text-sm">
                                        Kod pocztowy
                                        <input className={inputCls} value={form.data.delivery_address.postal_code} onChange={(e) => address('postal_code', e.target.value)} required />
                                    </label>
                                    <label className="text-sm">
                                        Miejscowość
                                        <input className={inputCls} value={form.data.delivery_address.city} onChange={(e) => address('city', e.target.value)} required />
                                    </label>
                                    <label className="text-sm">
                                        Telefon
                                        <input className={inputCls} value={form.data.delivery_address.phone} onChange={(e) => address('phone', e.target.value)} />
                                    </label>
                                    <label className="text-sm md:col-span-2">
                                        Uwagi
                                        <textarea className={inputCls} rows={2} value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} />
                                    </label>
                                </div>
                            </Card>

                            <div className="flex gap-2">
                                <button type="submit" className={btn} disabled={form.processing || lines.length === 0}>
                                    Złóż zamówienie
                                </button>
                                <Link href="/trade/available" className={btnOutline}>
                                    Wróć do ofert
                                </Link>
                            </div>
                        </form>
                    )}
                </div>
            </Content>
        </>
    );
}
