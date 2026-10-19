import { Head, router, useForm } from '@inertiajs/react';
import { Card, Content, ContentHeader } from '@/components/adminlte/blocks';
import { Badge, btn, btnDanger, date, Empty, Errors } from '@/components/trade/ui';

type Props = {
    token: string;
    invitation: {
        status: string;
        status_label: string;
        inviter: string | null;
        inviter_message: string | null;
        inviter_role: string;
        my_side: string;
        my_side_label: string;
        expires_at: string;
        represents: boolean;
        is_owner: boolean;
        units: { ref: string; name: string; kind: string; email: string | null }[];
    };
};

/**
 * Invitation to trade cooperation. Opening the link changes nothing: only a signed-in person who represents the invited
 * organization sees the choice of units and can accept or reject.
 */
export default function InvitationShow({ token, invitation }: Props) {
    const form = useForm({ units: [] as string[], all_current: false });
    const pending = invitation.status === 'pending';

    return (
        <>
            <Head title="Zaproszenie do współpracy" />
            <ContentHeader title="Zaproszenie do współpracy handlowej" crumbs={[{ label: 'Handel', href: '/trade/partners' }, { label: 'Zaproszenie' }]} />
            <Content>
                <Errors />
                <Card title={invitation.inviter ?? 'Zaproszenie'} color="primary">
                    <p className="text-sm">
                        <Badge status={invitation.status} label={invitation.status_label} /> Ważne do {date(invitation.expires_at)}
                    </p>
                    <p className="mt-2 text-sm">
                        Organizacja <strong>{invitation.inviter}</strong> zaprasza do współpracy handlowej.{' '}
                        {invitation.inviter_role === 'supplier' ? 'Będzie przedstawiać Wam oferty.' : 'Chce zamawiać w Waszych hurtowniach.'} Wasza rola: <strong>{invitation.my_side_label}</strong>.
                    </p>
                    {invitation.inviter_message && <p className="mt-2 rounded bg-[#f4f6f9] p-3 text-sm">{invitation.inviter_message}</p>}
                    <p className="mt-2 text-xs text-[#6c757d]">Współpraca nie nadaje nikomu członkostwa w obcej organizacji i nie zmienia struktury żadnej ze stron.</p>
                </Card>

                {pending && !invitation.represents && (
                    <Card color="warning">
                        <p className="text-sm">
                            Nie możesz odpowiedzieć na to zaproszenie: nie reprezentujesz zaproszonej organizacji (potrzebny jest jej właściciel albo kierownik jednostki).
                        </p>
                    </Card>
                )}

                {pending && invitation.represents && (
                    <Card title="Które Twoje jednostki biorą udział?" color="success">
                        {invitation.units.length === 0 && <Empty>Nie zarządzasz żadną jednostką, która może wystąpić w tej roli (dostawcą mogą być tylko hurtownie).</Empty>}
                        <div className="space-y-1 text-sm">
                            {invitation.units.map((unit) => (
                                <label key={unit.ref} className="flex items-center gap-2">
                                    <input
                                        type="checkbox"
                                        disabled={form.data.all_current}
                                        checked={form.data.units.includes(unit.ref)}
                                        onChange={(e) => form.setData('units', e.target.checked ? [...form.data.units, unit.ref] : form.data.units.filter((ref) => ref !== unit.ref))}
                                    />
                                    {unit.name} <span className="text-xs text-[#6c757d]">({unit.kind})</span>
                                    <span className="text-xs text-[#6c757d]">{unit.email ? `– ${unit.email}` : '– brak adresu e-mail jednostki'}</span>
                                </label>
                            ))}
                            {invitation.is_owner && (
                                <label className="mt-2 flex items-center gap-2">
                                    <input type="checkbox" checked={form.data.all_current} onChange={(e) => form.setData('all_current', e.target.checked)} />
                                    Wszystkie obecne jednostki organizacji (jawne zatwierdzenie; nowe jednostki nie dołączą automatycznie)
                                </label>
                            )}
                        </div>
                        <div className="mt-4 flex gap-2">
                            <button className={btn} disabled={form.processing || (!form.data.all_current && form.data.units.length === 0)} onClick={() => form.post(`/trade/invitations/${token}/accept`)}>
                                Przyjmij zaproszenie
                            </button>
                            <button className={btnDanger} onClick={() => confirm('Odrzucić zaproszenie?') && router.post(`/trade/invitations/${token}/reject`)}>
                                Odrzuć
                            </button>
                        </div>
                    </Card>
                )}
            </Content>
        </>
    );
}
