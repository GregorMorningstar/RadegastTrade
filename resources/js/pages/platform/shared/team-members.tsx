import { Head, router } from '@inertiajs/react';
import { Power } from 'lucide-react';
import { useState } from 'react';
import { Card, Content, ContentHeader } from '@/components/adminlte/blocks';
import { roleColor } from '@/lib/role-colors';

export type TeamMember = {
    key: string;
    /** Brak imienia oznacza zaproszenie, które nie zostało jeszcze przyjęte */
    name: string | null;
    email: string;
    phone: string | null;
    role: string;
    roleValue: string;
    unit: string;
    status: 'active' | 'invited' | 'locked' | 'inactive';
    detail: string | null;
    /** Account id (people only, not invitations) */
    userId?: number;
    /** Whether the viewer may switch this account on or off */
    canToggle?: boolean;
};

export type TeamMembersProps = {
    scope: 'organization' | 'headquarter' | 'branch';
    members: TeamMember[];
};

const statuses = {
    active: { label: 'aktywny', className: 'bg-[#28a745] text-white' },
    inactive: { label: 'nieaktywny', className: 'bg-[#6c757d] text-white' },
    invited: { label: 'zaproszony', className: 'bg-[#ffc107] text-[#1f2d3d]' },
    locked: { label: 'zablokowane zaproszenie', className: 'bg-[#dc3545] text-white' },
} as const;

/** Zespół: osoby i zaproszenia z rolami (organizacja albo siedziba sklepu / hurtowni). */
export default function TeamMembersPage({ scope, members }: TeamMembersProps) {
    const base = scope === 'organization' ? '/platform/organization' : null;
    const [busy, setBusy] = useState<number | null>(null);
    const [error, setError] = useState<string | null>(null);

    // Switches the account on or off (the organization owner for the people of the organization, a branch leader for the
    // workers of the branch). A deactivated person still logs in but only sees the "account is not active" page.
    const toggle = async (member: TeamMember) => {
        if (member.userId === undefined) {
            return;
        }

        const next = member.status === 'inactive';

        if (!next && !confirm(`Dezaktywować konto: ${member.name}? Po zalogowaniu zobaczy tylko stronę o wyłączonym koncie i będzie mógł pisać na czacie do Ciebie i administratora.`)) {
            return;
        }

        setBusy(member.userId);
        setError(null);

        try {
            const token = decodeURIComponent(document.cookie.split('; ').find((row) => row.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '');
            const response = await fetch(`/memberships/people/${member.userId}/active`, {
                method: 'PATCH',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': token },
                body: JSON.stringify({ active: next }),
            });

            if (!response.ok) {
                throw new Error(String(response.status));
            }

            router.reload({ only: ['members'] });
        } catch {
            setError('Nie udało się zmienić statusu konta.');
        } finally {
            setBusy(null);
        }
    };
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
                    {error && <p className="mb-3 text-sm text-[#dc3545]">{error}</p>}

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
                                                <span className={`rounded px-2 py-0.5 text-xs ${roleColor(member.roleValue)}`}>
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
                                                {member.canToggle && member.userId !== undefined && (
                                                    <button
                                                        type="button"
                                                        onClick={() => toggle(member)}
                                                        disabled={busy === member.userId}
                                                        className={`mt-1.5 flex items-center gap-1 rounded border px-2 py-0.5 text-xs disabled:opacity-60 ${
                                                            member.status === 'inactive'
                                                                ? 'border-[#28a745] text-[#1e7e34] hover:bg-[#f1fbf3]'
                                                                : 'border-[#dc3545] text-[#dc3545] hover:bg-[#fff5f5]'
                                                        }`}
                                                    >
                                                        <Power className="size-3" />
                                                        {member.status === 'inactive' ? 'Aktywuj' : 'Dezaktywuj'}
                                                    </button>
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
