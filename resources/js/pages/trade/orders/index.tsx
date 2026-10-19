import { Head, Link } from '@inertiajs/react';
import { Card, Content, ContentHeader } from '@/components/adminlte/blocks';
import { Badge, date, Empty, Errors, money, td, th, TradeTabs } from '@/components/trade/ui';

type Order = {
    id: number;
    number: string;
    status: string;
    status_label: string;
    currency: string;
    total: string;
    buyer_unit: string;
    seller_unit: string;
    placed_by: string;
    placed_at: string;
};

const statuses: [string | null, string][] = [
    [null, 'Wszystkie'],
    ['submitted', 'Złożone'],
    ['confirmed', 'Potwierdzone'],
    ['fulfilled', 'Wysłane'],
    ['received', 'Odebrane'],
    ['rejected', 'Odrzucone'],
    ['cancelled', 'Anulowane'],
];

export default function OrdersIndex({ orders, kind, status }: { orders: Order[]; kind: 'purchases' | 'sales'; status: string | null }) {
    const link = (k: string, s: string | null) => `/trade/orders?kind=${k}${s ? `&status=${s}` : ''}`;

    return (
        <>
            <Head title="Zamówienia" />
            <ContentHeader title="Zamówienia" crumbs={[{ label: 'Handel', href: '/trade/available' }, { label: 'Zamówienia' }]} />
            <Content>
                <TradeTabs current="orders" />
                <Errors />
                <Card title={kind === 'sales' ? 'Otrzymane od klientów' : 'Moje zakupy'} color="primary">
                    <div className="mb-3 flex flex-wrap gap-2 text-sm">
                        <Link href={link('purchases', status)} className={`rounded px-2 py-1 ${kind === 'purchases' ? 'bg-[#007bff] text-white' : 'text-[#007bff] hover:bg-[#e9ecef]'}`}>
                            Zakupowe
                        </Link>
                        <Link href={link('sales', status)} className={`rounded px-2 py-1 ${kind === 'sales' ? 'bg-[#007bff] text-white' : 'text-[#007bff] hover:bg-[#e9ecef]'}`}>
                            Otrzymane
                        </Link>
                        <span className="mx-2 text-[#adb5bd]">|</span>
                        {statuses.map(([key, label]) => (
                            <Link key={label} href={link(kind, key)} className={`rounded px-2 py-1 ${status === key ? 'bg-[#6c757d] text-white' : 'text-[#495057] hover:bg-[#e9ecef]'}`}>
                                {label}
                            </Link>
                        ))}
                    </div>

                    {orders.length === 0 ? (
                        <Empty>Brak zamówień w tym widoku.</Empty>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-sm">
                                <thead>
                                    <tr className="border-b border-[#dee2e6]">
                                        <th className={th}>Numer</th>
                                        <th className={th}>Kupujący</th>
                                        <th className={th}>Sprzedający</th>
                                        <th className={th}>Wartość brutto</th>
                                        <th className={th}>Status</th>
                                        <th className={th}>Złożone</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {orders.map((order) => (
                                        <tr key={order.id} className="border-b border-[#dee2e6] last:border-0">
                                            <td className={td}>
                                                <Link href={`/trade/orders/${order.id}`} className="text-[#007bff] hover:underline">
                                                    {order.number}
                                                </Link>
                                            </td>
                                            <td className={td}>
                                                {order.buyer_unit} <span className="text-xs text-[#6c757d]">({order.placed_by})</span>
                                            </td>
                                            <td className={td}>{order.seller_unit}</td>
                                            <td className={td}>{money(order.total, order.currency)}</td>
                                            <td className={td}>
                                                <Badge status={order.status} label={order.status_label} />
                                            </td>
                                            <td className={td}>{date(order.placed_at)}</td>
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
