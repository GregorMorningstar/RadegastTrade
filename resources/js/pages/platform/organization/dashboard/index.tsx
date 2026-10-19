import { Head, Link } from '@inertiajs/react';
import { Building2, Store, Users, Warehouse } from 'lucide-react';
import { useState } from 'react';
import Barcode from 'react-barcode';
import PersonDetailsModal from '@/components/person-details-modal';
import UserAvatar from '@/components/user-avatar';
import { chatWindow } from '@/lib/chat-window';
import {
    Card,
    Content,
    ContentHeader,
    SmallBox,
} from '@/components/adminlte/blocks';

type Props = {
    organization: {
        id: number;
        name: string;
        NIP: string | null;
        barcode: string | null;
    };
    stats: {
        warehouseHeadquarters: number;
        shopHeadquarters: number;
        people: number;
    };
    people: {
        data: {
            id: number;
            name: string;
            email: string;
            role: string;
            is_me: boolean;
            avatar_url: string;
            last_login: string | null;
        }[];
        current_page: number;
        last_page: number;
        from: number | null;
        to: number | null;
        total: number;
        links: { url: string | null; label: string; active: boolean }[];
    };
};

export default function OrganizationOwnerDashboard({
    organization,
    stats,
    people,
}: Props) {
    const [detailsId, setDetailsId] = useState<number | null>(null);

    return (
        <>
            <PersonDetailsModal personId={detailsId} onClose={() => setDetailsId(null)} />
            <Head title="Dashboard - Właściciel organizacji" />
            <ContentHeader
                title="Dashboard"
                crumbs={[
                    { label: 'Platforma', href: '/platform/organization' },
                    { label: 'Dashboard' },
                ]}
            />

            <Content>
                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <SmallBox
                        color="info"
                        value={stats.warehouseHeadquarters}
                        label="Siedziby hurtowni"
                        icon={Warehouse}
                    />
                    <SmallBox
                        color="success"
                        value={stats.shopHeadquarters}
                        label="Siedziby sklepów"
                        icon={Store}
                    />
                    <SmallBox
                        color="warning"
                        value={stats.people}
                        label="Osoby w organizacji"
                        icon={Users}
                    />
                    <SmallBox
                        color="primary"
                        value={organization.NIP ?? '—'}
                        label="NIP"
                        icon={Building2}
                    />
                </div>

                <div className="mt-4 grid gap-4 lg:grid-cols-3">
                    <Card
                        title={organization.name}
                        color="primary"
                        className="lg:col-span-1"
                    >
                        <p className="mb-3 text-sm text-[#6c757d]">
                            Kod kreskowy organizacji
                        </p>
                        {organization.barcode ? (
                            <Barcode
                                value={organization.barcode}
                                format="EAN13"
                                width={1.6}
                                height={50}
                                fontSize={12}
                                margin={0}
                                background="transparent"
                            />
                        ) : (
                            '—'
                        )}
                    </Card>

                    <Card
                        title="Osoby w organizacji"
                        color="success"
                        className="lg:col-span-2"
                    >
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-sm">
                                <thead>
                                    <tr className="border-b border-[#dee2e6]">
                                        <th className="py-2 pr-4 font-semibold">Nazwa</th>
                                        <th className="py-2 pr-4 font-semibold">E-mail</th>
                                        <th className="py-2 pr-4 font-semibold">Rola</th>
                                        <th className="py-2 pr-4 font-semibold">Ostatnie logowanie</th>
                                        <th className="py-2 text-right font-semibold">Akcje</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {people.data.map((person) => (
                                        <tr
                                            key={person.id}
                                            className="border-b border-[#dee2e6] last:border-0"
                                        >
                                            <td className="py-2 pr-4">
                                                <span className="flex items-center gap-2">
                                                    <UserAvatar src={person.avatar_url} name={person.name} size={28} />
                                                    {person.name}
                                                </span>
                                            </td>
                                            <td className="py-2 pr-4">{person.email}</td>
                                            <td className="py-2 pr-4">
                                                <span className="rounded bg-[#007bff] px-2 py-0.5 text-xs text-white">
                                                    {person.role}
                                                </span>
                                            </td>
                                            <td className="py-2 pr-4">{person.last_login ?? '—'}</td>
                                            <td className="py-2 text-right whitespace-nowrap">
                                                {!person.is_me && (
                                                    <button
                                                        type="button"
                                                        onClick={() => chatWindow.openWith(person.id)}
                                                        className="mr-1 rounded bg-[#0084ff] px-2 py-1 text-xs text-white hover:bg-[#0073e6]"
                                                    >
                                                        Czat
                                                    </button>
                                                )}
                                                <button
                                                    type="button"
                                                    onClick={() => setDetailsId(person.id)}
                                                    className="rounded border border-[#007bff] px-2 py-1 text-xs text-[#007bff] hover:bg-[#007bff] hover:text-white"
                                                >
                                                    Szczegóły
                                                </button>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        {people.last_page > 1 && (
                            <div className="mt-3 flex flex-wrap items-center justify-between gap-2 text-sm">
                                <span className="text-[#6c757d]">
                                    {people.from}–{people.to} z {people.total}
                                </span>
                                <nav className="flex flex-wrap gap-1">
                                    {people.links.map((link, i) => {
                                        const label = link.label
                                            .replace('&laquo;', '«')
                                            .replace('&raquo;', '»')
                                            .replace('Previous', 'Poprzednia')
                                            .replace('Next', 'Następna');
                                        const cls = `rounded border px-2.5 py-1 ${
                                            link.active
                                                ? 'border-[#007bff] bg-[#007bff] text-white'
                                                : 'border-[#dee2e6] text-[#007bff]'
                                        }`;

                                        return link.url ? (
                                            <Link
                                                key={i}
                                                href={link.url}
                                                preserveScroll
                                                className={cls}
                                            >
                                                {label}
                                            </Link>
                                        ) : (
                                            <span
                                                key={i}
                                                className={`${cls} opacity-50`}
                                            >
                                                {label}
                                            </span>
                                        );
                                    })}
                                </nav>
                            </div>
                        )}
                    </Card>
                </div>
            </Content>
        </>
    );
}
