import { Head, router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Card, Content, ContentHeader } from '@/components/adminlte/blocks';
import { btn, btnDanger, btnOutline, Errors, inputCls, TradeTabs } from '@/components/trade/ui';

type Item = {
    name: string;
    sku: string;
    barcode: string;
    unit_of_measure: string;
    unit_price: string;
    vat_rate: string;
    min_qty: string;
    qty_step: string;
    max_qty: string;
};

type Version = {
    title: string;
    description: string | null;
    currency: string;
    valid_from: string | null;
    valid_to: string | null;
    delivery_terms: string | null;
    items: Partial<Item>[];
};

type Props = {
    offer: { id: number; number: string; draft: Version | null; version: Version | null } | null;
    units: { ref: string; name: string }[];
};

const emptyItem = (): Item => ({ name: '', sku: '', barcode: '', unit_of_measure: 'szt.', unit_price: '', vat_rate: '23', min_qty: '1', qty_step: '1', max_qty: '' });

/** Create an offer (a draft) or edit its draft; a published version is never edited, a change opens a new draft. */
export default function OfferEdit({ offer, units }: Props) {
    const source = offer?.draft ?? offer?.version ?? null;
    const form = useForm({
        unit_ref: units[0]?.ref ?? '',
        title: source?.title === 'Nowa oferta' ? '' : (source?.title ?? ''),
        description: source?.description ?? '',
        currency: source?.currency ?? 'PLN',
        valid_from: source?.valid_from ?? '',
        valid_to: source?.valid_to ?? '',
        delivery_terms: source?.delivery_terms ?? '',
        items: (source?.items.length ? source.items.map((item) => ({ ...emptyItem(), ...Object.fromEntries(Object.entries(item).map(([k, v]) => [k, v ?? ''])) })) : [emptyItem()]) as Item[],
    });

    const setItem = (index: number, field: keyof Item, value: string) =>
        form.setData('items', form.data.items.map((item, i) => (i === index ? { ...item, [field]: value } : item)));

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (offer) {
            form.put(`/trade/offers/${offer.id}`);
        } else {
            form.post('/trade/offers');
        }
    };

    return (
        <>
            <Head title={offer ? `Edycja ${offer.number}` : 'Nowa oferta'} />
            <ContentHeader
                title={offer ? `Edycja oferty ${offer.number}` : 'Nowa oferta'}
                crumbs={[{ label: 'Handel', href: '/trade/available' }, { label: 'Moje oferty', href: '/trade/offers' }, { label: offer ? offer.number : 'Nowa' }]}
            />
            <Content>
                <TradeTabs current="offers" />
                <Errors />
                <form onSubmit={submit} className="space-y-4">
                    <Card title="Dane oferty" color="primary">
                        {offer?.version && !offer.draft && (
                            <p className="mb-3 rounded bg-[#fff3cd] px-3 py-2 text-sm text-[#856404]">
                                Opublikowana wersja nie zmienia się. Zapisanie tego formularza tworzy nową wersję roboczą, którą potem publikujesz.
                            </p>
                        )}
                        <div className="grid gap-3 md:grid-cols-2">
                            {!offer && (
                                <label className="text-sm">
                                    Hurtownia
                                    <select className={inputCls} value={form.data.unit_ref} onChange={(e) => form.setData('unit_ref', e.target.value)} required>
                                        {units.map((unit) => (
                                            <option key={unit.ref} value={unit.ref}>
                                                {unit.name}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                            )}
                            <label className="text-sm">
                                Tytuł
                                <input className={inputCls} value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} maxLength={200} required />
                            </label>
                            <label className="text-sm">
                                Waluta
                                <input className={inputCls} value={form.data.currency} onChange={(e) => form.setData('currency', e.target.value.toUpperCase())} maxLength={3} required />
                            </label>
                            <label className="text-sm">
                                Obowiązuje od
                                <input type="datetime-local" className={inputCls} value={form.data.valid_from ?? ''} onChange={(e) => form.setData('valid_from', e.target.value)} />
                            </label>
                            <label className="text-sm">
                                Obowiązuje do
                                <input type="datetime-local" className={inputCls} value={form.data.valid_to ?? ''} onChange={(e) => form.setData('valid_to', e.target.value)} />
                            </label>
                            <label className="text-sm md:col-span-2">
                                Opis
                                <textarea className={inputCls} rows={3} value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} />
                            </label>
                            <label className="text-sm md:col-span-2">
                                Warunki dostawy
                                <textarea className={inputCls} rows={2} value={form.data.delivery_terms} onChange={(e) => form.setData('delivery_terms', e.target.value)} />
                            </label>
                        </div>
                    </Card>

                    <Card title="Pozycje" color="success">
                        <div className="space-y-3">
                            {form.data.items.map((item, index) => (
                                <div key={index} className="grid gap-2 rounded border border-[#dee2e6] p-3 md:grid-cols-6">
                                    <label className="text-xs md:col-span-2">
                                        Nazwa
                                        <input className={inputCls} value={item.name} onChange={(e) => setItem(index, 'name', e.target.value)} required />
                                    </label>
                                    <label className="text-xs">
                                        SKU
                                        <input className={inputCls} value={item.sku} onChange={(e) => setItem(index, 'sku', e.target.value)} />
                                    </label>
                                    <label className="text-xs">
                                        Kod kreskowy
                                        <input className={inputCls} value={item.barcode} maxLength={13} onChange={(e) => setItem(index, 'barcode', e.target.value)} />
                                    </label>
                                    <label className="text-xs">
                                        Jednostka miary
                                        <input className={inputCls} value={item.unit_of_measure} onChange={(e) => setItem(index, 'unit_of_measure', e.target.value)} required />
                                    </label>
                                    <label className="text-xs">
                                        Cena netto
                                        <input className={inputCls} type="number" step="0.0001" min="0" value={item.unit_price} onChange={(e) => setItem(index, 'unit_price', e.target.value)} required />
                                    </label>
                                    <label className="text-xs">
                                        VAT %
                                        <input className={inputCls} type="number" step="0.01" min="0" max="100" value={item.vat_rate} onChange={(e) => setItem(index, 'vat_rate', e.target.value)} />
                                    </label>
                                    <label className="text-xs">
                                        Min. ilość
                                        <input className={inputCls} type="number" step="0.001" min="0" value={item.min_qty} onChange={(e) => setItem(index, 'min_qty', e.target.value)} />
                                    </label>
                                    <label className="text-xs">
                                        Wielokrotność
                                        <input className={inputCls} type="number" step="0.001" min="0" value={item.qty_step} onChange={(e) => setItem(index, 'qty_step', e.target.value)} />
                                    </label>
                                    <label className="text-xs">
                                        Maks. ilość
                                        <input className={inputCls} type="number" step="0.001" min="0" value={item.max_qty} onChange={(e) => setItem(index, 'max_qty', e.target.value)} />
                                    </label>
                                    <div className="flex items-end">
                                        {form.data.items.length > 1 && (
                                            <button type="button" className={btnDanger} onClick={() => form.setData('items', form.data.items.filter((_, i) => i !== index))}>
                                                Usuń pozycję
                                            </button>
                                        )}
                                    </div>
                                </div>
                            ))}
                        </div>
                        <button type="button" className={`${btnOutline} mt-3`} onClick={() => form.setData('items', [...form.data.items, emptyItem()])}>
                            Dodaj pozycję
                        </button>
                    </Card>

                    <div className="flex gap-2">
                        <button type="submit" className={btn} disabled={form.processing}>
                            Zapisz szkic
                        </button>
                        <button type="button" className={btnOutline} onClick={() => router.visit(offer ? `/trade/offers/${offer.id}` : '/trade/offers')}>
                            Anuluj
                        </button>
                    </div>
                </form>
            </Content>
        </>
    );
}
