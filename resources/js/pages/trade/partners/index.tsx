import { Head, router, useForm } from '@inertiajs/react';
import { Card, Content, ContentHeader } from '@/components/adminlte/blocks';
import { Badge, btn, btnDanger, btnOutline, date, Empty, Errors, inputCls, td, th, TradeTabs } from '@/components/trade/ui';

type Unit = { ref: string; name: string; kind: string; email?: string | null };

type Relationship = {
    id: number;
    status: string;
    status_label: string;
    my_side: string;
    my_side_label: string;
    partner: string;
    my_units: Unit[];
    partner_units: Unit[];
    can_manage: boolean;
    started_at: string | null;
};

type Sent = { id: number; partner: string; role_label: string; status: string; status_label: string; expires_at: string; can_cancel: boolean };

type Props = {
    relationships: Relationship[];
    sent: Sent[];
    can_invite: boolean;
    is_owner: boolean;
    units: (Unit & { warehouse: boolean })[];
};

function act(url: string, method: 'post' | 'delete' = 'post', data: Record<string, unknown> = {}) {
    router[method](url, data as never, { preserveScroll: true });
}

function InviteForm({ units, isOwner }: { units: Props['units']; isOwner: boolean }) {
    const form = useForm({ inviter_role: 'supplier', invitee_email: '', units: [] as string[], all_current: false, message: '' });
    const usable = units.filter((unit) => form.data.inviter_role === 'buyer' || unit.warehouse);

    return (
        <form
            className="space-y-3"
            onSubmit={(e) => {
                e.preventDefault();
                form.post('/trade/partners/invitations', { preserveScroll: true, onSuccess: () => form.reset('invitee_email', 'message') });
            }}
        >
            <div className="flex flex-wrap gap-4 text-sm">
                <label className="flex items-center gap-1">
                    <input type="radio" checked={form.data.inviter_role === 'supplier'} onChange={() => form.setData({ ...form.data, inviter_role: 'supplier', units: [] })} />
                    Moja organizacja przedstawia oferty (hurtownie)
                </label>
                <label className="flex items-center gap-1">
                    <input type="radio" checked={form.data.inviter_role === 'buyer'} onChange={() => form.setData({ ...form.data, inviter_role: 'buyer', units: [] })} />
                    Moja organizacja zamawia
                </label>
            </div>
            <label className="block text-sm">
                Adres e-mail osoby z organizacji kontrahenta
                <input className={inputCls} type="email" value={form.data.invitee_email} onChange={(e) => form.setData('invitee_email', e.target.value)} required />
            </label>
            <fieldset className="text-sm">
                <legend className="font-semibold">Moje jednostki objęte współpracą</legend>
                {usable.length === 0 && <Empty>Nie masz jednostek, które mogą wystąpić w tej roli.</Empty>}
                {usable.map((unit) => (
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
                {isOwner && (
                    <label className="mt-2 flex items-center gap-2">
                        <input type="checkbox" checked={form.data.all_current} onChange={(e) => form.setData('all_current', e.target.checked)} />
                        Wszystkie obecne jednostki (świadome zatwierdzenie; nowe jednostki nie dołączą automatycznie)
                    </label>
                )}
            </fieldset>
            <label className="block text-sm">
                Wiadomość (opcjonalnie)
                <textarea className={inputCls} rows={2} maxLength={500} value={form.data.message} onChange={(e) => form.setData('message', e.target.value)} />
            </label>
            <button type="submit" className={btn} disabled={form.processing}>
                Wyślij zaproszenie
            </button>
            <p className="text-xs text-[#6c757d]">
                Zaproszenie dociera tylko do organizacji, która istnieje w systemie. Odpowiedź jest taka sama, gdy adres jest nieznany. Odbiorca wybiera swoje jednostki
                przy akceptacji, a link nic nie akceptuje bez zalogowania osoby reprezentującej organizację.
            </p>
        </form>
    );
}

export default function PartnersIndex({ relationships, sent, can_invite, is_owner, units }: Props) {
    return (
        <>
            <Head title="Współpraca handlowa" />
            <ContentHeader title="Współpraca handlowa" crumbs={[{ label: 'Handel', href: '/trade/available' }, { label: 'Współpraca' }]} />
            <Content>
                <TradeTabs current="partners" />
                <Errors />
                <div className="space-y-4">
                    <Card title="Kontrahenci" color="primary">
                        {relationships.length === 0 ? (
                            <Empty>Nie masz jeszcze współpracy z inną organizacją.</Empty>
                        ) : (
                            <div className="space-y-4">
                                {relationships.map((relationship) => (
                                    <div key={relationship.id} className="rounded border border-[#dee2e6] p-3 text-sm">
                                        <div className="flex flex-wrap items-center justify-between gap-2">
                                            <div>
                                                <strong>{relationship.partner}</strong> <Badge status={relationship.status} label={relationship.status_label} />{' '}
                                                <span className="text-xs text-[#6c757d]">Twoja rola: {relationship.my_side_label}. Od {date(relationship.started_at)}</span>
                                            </div>
                                            {relationship.can_manage && relationship.status !== 'ended' && (
                                                <div className="flex gap-2">
                                                    {relationship.status === 'active' && (
                                                        <button className={btnOutline} onClick={() => confirm('Zawiesić współpracę? Nowe udostępnienia i zamówienia zostaną zablokowane.') && act(`/trade/partners/${relationship.id}/suspend`)}>
                                                            Zawieś
                                                        </button>
                                                    )}
                                                    {relationship.status === 'suspended' && (
                                                        <button className={btnOutline} onClick={() => act(`/trade/partners/${relationship.id}/resume`)}>
                                                            Wznów
                                                        </button>
                                                    )}
                                                    <button className={btnDanger} onClick={() => confirm('Zakończyć współpracę? Historia i zamówienia zostaną zachowane.') && act(`/trade/partners/${relationship.id}/end`)}>
                                                        Zakończ
                                                    </button>
                                                </div>
                                            )}
                                        </div>
                                        <div className="mt-2 grid gap-3 md:grid-cols-2">
                                            <div>
                                                <p className="font-semibold">Twoje jednostki w zakresie</p>
                                                <ul>
                                                    {relationship.my_units.map((unit) => (
                                                        <li key={unit.ref}>
                                                            {unit.name} <span className="text-xs text-[#6c757d]">({unit.kind})</span>{' '}
                                                            {relationship.status !== 'ended' && (
                                                                <button className="text-xs text-[#dc3545]" onClick={() => confirm('Wyłączyć jednostkę ze współpracy?') && act(`/trade/partners/${relationship.id}/units`, 'delete', { unit: unit.ref })}>
                                                                    wyłącz
                                                                </button>
                                                            )}
                                                        </li>
                                                    ))}
                                                </ul>
                                            </div>
                                            <div>
                                                <p className="font-semibold">Jednostki kontrahenta w zakresie</p>
                                                <ul>
                                                    {relationship.partner_units.map((unit) => (
                                                        <li key={unit.ref}>
                                                            {unit.name} <span className="text-xs text-[#6c757d]">({unit.kind})</span>
                                                        </li>
                                                    ))}
                                                </ul>
                                            </div>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </Card>

                    <Card title="Zaproszenia wysłane" color="info">
                        {sent.length === 0 ? (
                            <Empty>Nie wysłano zaproszeń.</Empty>
                        ) : (
                            <table className="w-full text-left text-sm">
                                <thead>
                                    <tr className="border-b border-[#dee2e6]">
                                        <th className={th}>Organizacja</th>
                                        <th className={th}>Rola</th>
                                        <th className={th}>Status</th>
                                        <th className={th}>Ważne do</th>
                                        <th className={th}></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {sent.map((invitation) => (
                                        <tr key={invitation.id} className="border-b border-[#dee2e6] last:border-0">
                                            <td className={td}>{invitation.partner}</td>
                                            <td className={td}>{invitation.role_label}</td>
                                            <td className={td}>
                                                <Badge status={invitation.status} label={invitation.status_label} />
                                            </td>
                                            <td className={td}>{date(invitation.expires_at)}</td>
                                            <td className={td}>
                                                {invitation.can_cancel && (
                                                    <button className="text-xs text-[#dc3545]" onClick={() => act(`/trade/partners/invitations/${invitation.id}`, 'delete')}>
                                                        cofnij
                                                    </button>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}
                        <p className="mt-3 text-xs text-[#6c757d]">Zaproszenia otrzymane przychodzą e-mailem; link prowadzi na stronę, na której osoba reprezentująca organizację je przyjmuje albo odrzuca.</p>
                    </Card>

                    {can_invite && (
                        <Card title="Zaproś do współpracy" color="success">
                            <InviteForm units={units} isOwner={is_owner} />
                        </Card>
                    )}
                </div>
            </Content>
        </>
    );
}
