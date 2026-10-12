import { Head } from '@inertiajs/react';
import { Building2, Network, ShieldCheck, Users } from 'lucide-react';
import {
    Card,
    Content,
    ContentHeader,
    SmallBox,
} from '@/components/adminlte/blocks';
import UserBarcodeCard from '@/components/user-barcode-card';

type Props = {
    stats: {
        users: number;
        organizations: number;
        teams: number;
        roles: number;
    };
    latestUsers: {
        id: number;
        name: string;
        email: string;
        role: string;
        barcode: string | null;
        last_login: string | null;
    }[];
};

export default function AdminDashboard({ stats, latestUsers }: Props) {
    return (
        <>
            <Head title="Dashboard - Administrator" />
            <ContentHeader
                title="Dashboard"
                crumbs={[
                    { label: 'Platforma', href: '/platform/admin' },
                    { label: 'Dashboard' },
                ]}
            />

            <Content>
                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <SmallBox color="info" value={stats.users} label="Użytkownicy" icon={Users} />
                    <SmallBox color="success" value={stats.organizations} label="Organizacje" icon={Network} />
                    <SmallBox color="warning" value={stats.teams} label="Zespoły" icon={Building2} />
                    <SmallBox color="danger" value={stats.roles} label="Role w systemie" icon={ShieldCheck} />
                </div>

                <Card title="Ostatni użytkownicy" color="primary" className="mt-4">
                    <div className="overflow-x-auto">
                        <table className="w-full text-left text-sm">
                            <thead>
                                <tr className="border-b border-[#dee2e6]">
                                    <th className="py-2 pr-4 font-semibold">#</th>
                                    <th className="py-2 pr-4 font-semibold">Nazwa</th>
                                    <th className="py-2 pr-4 font-semibold">E-mail</th>
                                    <th className="py-2 pr-4 font-semibold">Rola</th>
                                    <th className="py-2 pr-4 font-semibold">Barcode</th>
                                    <th className="py-2 font-semibold">Ostatnie logowanie</th>
                                </tr>
                            </thead>
                            <tbody>
                                {latestUsers.map((user) => (
                                    <tr key={user.id} className="border-b border-[#dee2e6] last:border-0">
                                        <td className="py-2 pr-4">{user.id}</td>
                                        <td className="py-2 pr-4">{user.name}</td>
                                        <td className="py-2 pr-4">{user.email}</td>
                                        <td className="py-2 pr-4">
                                            <span className="rounded bg-[#007bff] px-2 py-0.5 text-xs text-white">
                                                {user.role}
                                            </span>
                                        </td>
                                        <td className="py-2 pr-4 font-mono">{user.barcode}</td>
                                        <td className="py-2">{user.last_login ?? '—'}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </Card>
                <UserBarcodeCard className="mt-4 max-w-md" />
            </Content>
        </>
    );
}
