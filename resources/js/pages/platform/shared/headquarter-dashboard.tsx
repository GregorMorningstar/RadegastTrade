import { Head } from '@inertiajs/react';
import { Building2, Store, Users, Warehouse } from 'lucide-react';
import Barcode from 'react-barcode';
import { Card, Content, ContentHeader, SmallBox } from '@/components/adminlte/blocks';
import UserBarcodeCard from '@/components/user-barcode-card';

export type HeadquarterDashboardProps = {
    headquarter: {
        id: number;
        name: string;
        barcode: string | null;
        NIP: string | null;
        organization: string;
    } | null;
    stats: { people: number };
    people: {
        id: number;
        name: string;
        email: string;
        role: string;
        last_login: string | null;
    }[];
};

/** Dashboard kierownika siedziby (sklepu lub hurtowni), w stylu dashboardu organizacji. */
export default function HeadquarterDashboard({
    group,
    title,
    headquarter,
    stats,
    people,
}: HeadquarterDashboardProps & { group: 'shop' | 'warehouse'; title: string }) {
    const Icon = group === 'shop' ? Store : Warehouse;

    if (!headquarter) {
        return (
            <>
                <Head title={title} />
                <ContentHeader title="Dashboard" crumbs={[{ label: 'Dashboard' }]} />
                <Content>
                    <Card title="Dashboard" color="warning">
                        To konto nie ma przypisanej siedziby.
                    </Card>
                </Content>
            </>
        );
    }

    return (
        <>
            <Head title={title} />
            <ContentHeader
                title="Dashboard"
                crumbs={[
                    { label: 'Panel', href: `/${group}/owner` },
                    { label: 'Dashboard' },
                    
                ]}
            />

            <Content>
                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    <SmallBox
                        color={group === 'shop' ? 'success' : 'info'}
                        value={headquarter.name}
                        label={group === 'shop' ? 'Siedziba sklepu' : 'Siedziba hurtowni'}
                        icon={Icon}
                    />
                    <SmallBox color="warning" value={stats.people} label="Osoby w siedzibie" icon={Users} />
                    <SmallBox color="primary" value={headquarter.NIP ?? '—'} label="NIP" icon={Building2} />
                </div>

                <div className="mt-4 grid gap-4 lg:grid-cols-3">
                    <Card title={headquarter.name} color="primary" className="lg:col-span-1">
                        <p className="mb-1 text-sm text-[#6c757d]">Organizacja</p>
                        <p className="mb-3 text-sm font-semibold">{headquarter.organization}</p>
                        <p className="mb-3 text-sm text-[#6c757d]">Kod kreskowy siedziby</p>
                        {headquarter.barcode ? (
                            <Barcode
                                value={headquarter.barcode}
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

                    <Card title="Osoby w siedzibie" color="success" className="lg:col-span-2">
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
                                        <tr key={person.id} className="border-b border-[#dee2e6] last:border-0">
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
                <UserBarcodeCard className="mt-4 max-w-md" />
            </Content>
        </>
    );
}
