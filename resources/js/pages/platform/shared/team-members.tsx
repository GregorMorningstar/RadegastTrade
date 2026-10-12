import { Head } from '@inertiajs/react';
import { Card, Content, ContentHeader } from '@/components/adminlte/blocks';

export type TeamMember = {
    key: string;
    /** Brak imienia oznacza zaproszenie, które nie zostało jeszcze przyjęte */
    name: string | null;
    email: string;
    phone: string | null;
    role: string;
    roleValue: string;
    unit: string;
    status: 'active' | 'invited' | 'locked';
    detail: string | null;
};

export type TeamMembersProps = {
    scope: 'organization' | 'headquarter' | 'branch';
    members: TeamMember[];
};

const roleColors: Record<string, string> = {
    organization_owner: 'bg-[#6f42c1]',
    shop_owner: 'bg-[#28a745]',
    warehouse_owner: 'bg-[#17a2b8]',
    shop_leader: 'bg-[#20c997] text-[#1f2d3d]',
    warehouse_leader: 'bg-[#6610f2]',
    shop_worker: 'bg-[#fd7e14]',
    warehouse_worker: 'bg-[#e83e8c]',
};

const statuses = {
    active: { label: 'aktywny', className: 'bg-[#28a745] text-white' },
    invited: { label: 'zaproszony', className: 'bg-[#ffc107] text-[#1f2d3d]' },
    locked: { label: 'zablokowane zaproszenie', className: 'bg-[#dc3545] text-white' },
} as const;

/** Zespół: osoby i zaproszenia z rolami (organizacja albo siedziba sklepu / hurtowni). */
export default function TeamMembersPage({ scope, members }: TeamMembersProps) {
    const base = scope === 'organization' ? '/platform/organization' : null;
    const intro =
        scope === 'organization'
            ? 'Wszystkie osoby w Twojej organizacji: organizatorzy, kierownicy siedzib sklepów i hurtowni oraz kierownicy placówek, razem z oczekującymi zaproszeniami.'
            : scope === 'branch'
              ? 'Zespół Twojej placówki: kierownik placówki i pracownicy.'
              : 'Organizator (właściciel organizacji), kierownicy tej siedziby i kierownicy jej placówek, razem z zaproszeniami, które czekają na przyjęcie.';

    return (
        <>
            <Head title="Zespół" />
            <ContentHeader
                title="Zespół"
                crumbs={[
                    base ? { label: 'Platforma', href: base } : { label: 'Panel' },
                    { label: 'Zespół' },
                ]}
            />
            <Content>
                <Card title={`Zespół (${members.length})`} color="primary">
                    <p className="mb-3 text-sm text-[#6c757d]">{intro}</p>

                    {members.length === 0 ? (
                        <p className="text-sm text-[#6c757d]">Nie ma jeszcze żadnych osób.</p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-sm">
                                <thead>
                                    <tr className="border-b border-[#dee2e6]">
                                        <th className="py-2 pr-4 font-semibold">Osoba</th>
                                        <th className="py-2 pr-4 font-semibold">E-mail</th>
                                        <th className="py-2 pr-4 font-semibold">Telefon</th>
                                        <th className="py-2 pr-4 font-semibold">Rola</th>
                                        <th className="py-2 pr-4 font-semibold">Jednostka</th>
                                        <th className="py-2 font-semibold">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {members.map((member) => (
                                        <tr
                                            key={member.key}
                                            className="border-b border-[#dee2e6] align-top last:border-0"
                                        >
                                            <td className="py-2 pr-4 font-medium">{member.name ?? '—'}</td>
                                            <td className="py-2 pr-4">{member.email}</td>
                                            <td className="py-2 pr-4">{member.phone ?? '—'}</td>
                                            <td className="py-2 pr-4">
                                                <span
                                                    className={`rounded px-2 py-0.5 text-xs text-white ${roleColors[member.roleValue] ?? 'bg-[#6c757d]'}`}
                                                >
                                                    {member.role}
                                                </span>
                                            </td>
                                            <td className="py-2 pr-4">{member.unit}</td>
                                            <td className="py-2">
                                                <span
                                                    className={`rounded px-2 py-0.5 text-xs ${statuses[member.status].className}`}
                                                >
                                                    {statuses[member.status].label}
                                                </span>
                                                {member.detail && (
                                                    <div className="mt-1 text-xs text-[#6c757d]">
                                                        {member.detail}
                                                    </div>
                                                )}
                                            </td>
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
