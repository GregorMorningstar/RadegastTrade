import { Head, router } from '@inertiajs/react';
import Barcode from 'react-barcode';
import { Card, Content, ContentHeader } from '@/components/adminlte/blocks';
import { Badge, btn, btnDanger, btnOutline, date, Errors, money, td, th, TradeTabs } from '@/components/trade/ui';

type Order = {
    id: number;
    number: string;
    barcode: string | null;
    status: string;
    status_label: string;
    currency: string;
    total: string;
    subtotal: string;
    tax_total: string;
    buyer_unit: string;
    seller_unit: string;
    placed_by: string;
    placed_at: string;
    is_seller: boolean;
    offer_version: number | null;
    delivery_address: Record<string, string>;
    notes: string | null;
    actions: { to: string; label: string; needs_reason: boolean }[];
    items: { id: number; sku: string | null; name: string; unit_of_measure: string; qty: string; unit_price: string; vat_rate: string | null; line_net: string; line_gross: string }[];
    history: { from: string | null; to: string; by: string | null; reason: string | null; at: string }[];
};

/** An order with the copy made when it was placed, the history of its statuses and the actions this person may take. */
export default function OrderShow({ order }: { order: Order }) {
    const change = (to: string, needsReason: boolean, label: string) => {
        const reason = needsReason ? prompt('Powód (wymagany):') : confirm(`${label}?`) ? '' : null;

        if (reason === null || (needsReason && reason.trim() === '')) {
            return;
        }

        router.post(`/trade/orders/${order.id}/status`, { to, reason: reason || undefined }, { preserveScroll: true });
    };

    return (
        <>
            <Head title={order.number} />
            <ContentHeader title={`Zamówienie ${order.number}`} crumbs={[{ label: 'Handel', href: '/trade/available' }, { label: 'Zamówienia', href: '/trade/orders' }, { label: order.number }]} />
            <Content>
                <TradeTabs current="orders" />
                <Errors />
                <div className="space-y-4">
                    <Card title="Podsumowanie" color="primary">
                        <p className="text-sm">
                            <Badge status={order.status} label={order.status_label} /> {order.buyer_unit} → {order.seller_unit}. Złożył(a): {order.placed_by}, {date(order.placed_at)}. Wersja oferty: {order.offer_version ?? '—'}.
                        </p>
                        {order.barcode && (
                            <div className="mt-3">
                                <p className="mb-1 text-xs text-[#6c757d]">Kod kreskowy zamówienia (do skanowania przy odbiorze)</p>
                                <Barcode value={order.barcode} format="EAN13" width={1.6} height={40} fontSize={11} margin={0} background="transparent" />
                            </div>
                        )}
                        {order.actions.length > 0 && (
                            <div className="mt-3 flex flex-wrap gap-2">
                                {order.actions.map((action) => (
                                    <button
                                        key={action.to}
                                        className={action.to === 'rejected' || action.to === 'cancelled' ? btnDanger : action.to === 'received' || action.to === 'confirmed' ? btn : btnOutline}
                                        onClick={() => change(action.to, action.needs_reason, action.label)}
                                    >
                                        {action.to === 'fulfilled' ? 'Oznacz jako wysłane' : action.to === 'received' ? 'Potwierdź odbiór' : action.to === 'cancelled' ? 'Anuluj zamówienie' : action.label}
                                    </button>
                                ))}
                            </div>
                        )}
                    </Card>

                    <Card title="Pozycje (kopia z chwili złożenia)" color="info">
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-sm">
                                <thead>
                                    <tr className="border-b border-[#dee2e6]">
                                        <th className={th}>Produkt</th>
                                        <th className={th}>Ilość</th>
                                        <th className={th}>Cena netto</th>
                                        <th className={th}>VAT</th>
                                        <th className={th}>Netto</th>
                                        <th className={th}>Brutto</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {order.items.map((item) => (
                                        <tr key={item.id} className="border-b border-[#dee2e6] last:border-0">
                                            <td className={td}>
                                                {item.name} {item.sku && <span className="text-xs text-[#6c757d]">({item.sku})</span>}
                                            </td>
                                            <td className={td}>
                                                {Number(item.qty)} {item.unit_of_measure}
                                            </td>
                                            <td className={td}>{money(item.unit_price, order.currency)}</td>
                                            <td className={td}>{item.vat_rate === null ? '—' : `${Number(item.vat_rate)}%`}</td>
                                            <td className={td}>{money(item.line_net, order.currency)}</td>
                                            <td className={td}>{money(item.line_gross, order.currency)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        <p className="mt-3 text-sm">
                            Netto {money(order.subtotal, order.currency)}, VAT {money(order.tax_total, order.currency)}, razem <strong>{money(order.total, order.currency)}</strong>.
                        </p>
                    </Card>

                    <div className="grid gap-4 md:grid-cols-2">
                        <Card title="Adres dostawy">
                            <p className="text-sm">
                                {order.delivery_address.name}
                                <br />
                                {order.delivery_address.street}
                                <br />
                                {order.delivery_address.postal_code} {order.delivery_address.city}
                                {order.delivery_address.phone && (
                                    <>
                                        <br />
                                        tel. {order.delivery_address.phone}
                                    </>
                                )}
                            </p>
                            {order.notes && <p className="mt-2 text-sm text-[#6c757d]">Uwagi: {order.notes}</p>}
                        </Card>
                        <Card title="Historia statusów">
                            <ul className="space-y-1 text-sm">
                                {order.history.map((row, index) => (
                                    <li key={index}>
                                        {date(row.at)}: {row.from ? `${row.from} → ` : ''}
                                        <strong>{row.to}</strong> {row.by && <>({row.by})</>}
                                        {row.reason && <span className="text-[#6c757d]"> – {row.reason}</span>}
                                    </li>
                                ))}
                            </ul>
                        </Card>
                    </div>
                </div>
            </Content>
        </>
    );
}
