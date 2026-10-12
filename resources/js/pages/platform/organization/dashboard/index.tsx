import { Head } from '@inertiajs/react';
import { Building2, Store, Users, Warehouse } from 'lucide-react';
import Barcode from 'react-barcode';
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
        id: number;
        name: string;
        email: string;
        role: string;
        last_login: string | null;
    }[];
};

export default function OrganizationOwnerDashboard({
    organization,
    stats,
    people,
}: Props) {
    return (
        <>
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
                                        <th className="py-2 font-semibold">Ostatnie logowanie</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {people.map((person) => (
                                        <tr
                                            key={person.id}
                                            className="border-b border-[#dee2e6] last:border-0"
                                        >
                                            <td className="py-2 pr-4">{person.name}</td>
                                            <td className="py-2 pr-4">{person.email}</td>
                                            <td className="py-2 pr-4">
                                                <span className="rounded bg-[#007bff] px-2 py-0.5 text-xs text-white">
                                                    {person.role}
                                                </span>
                                            </td>
                                            <td className="py-2">{person.last_login ?? '—'}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </Card>
                </div>
            </Content>
        </>
    );
}
