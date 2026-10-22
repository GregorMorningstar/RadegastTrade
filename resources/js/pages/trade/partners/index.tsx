import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Card, Content, ContentHeader } from '@/components/adminlte/blocks';
import { ColleagueInvitations, type ColleaguesProps } from '@/components/trade/colleague-invitations';
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
    colleagues: ColleaguesProps;
    own: { shops: string[]; warehouses: string[] };
    targets: { ref: string; name: string; organization_id: number; organization: string; warehouse: boolean; taken: string[] }[];
};

function act(url: string, method: 'post' | 'delete' = 'post', data: Record<string, unknown> = {}) {
    router[method](url, data as never, { preserveScroll: true });
}

/** When the person has exactly one unit that fits the role, it is already chosen. */
function only(units: Props['units'], role: 'supplier' | 'buyer'): string[] {
    const fitting = units.filter((unit) => role === 'buyer' || unit.warehouse);

    return fitting.length === 1 ? [fitting[0].ref] : [];
}

function InviteForm({ units, isOwner, targets }: { units: Props['units']; isOwner: boolean; targets: Props['targets'] }) {
    const form = useForm({ inviter_role: units.some((unit) => unit.warehouse) ? 'supplier' : 'buyer', target_units: [] as string[], units: only(units, units.some((unit) => unit.warehouse) ? 'supplier' : 'buyer'), all_current: false, message: '' });
    // every unit the person sees; a shop only buys, so it cannot be chosen when the organization presents offers
    // who can be invited: when my organization orders, only warehouses sell; when it presents offers, any unit can buy
    const invitable = targets.filter((target) => (form.data.inviter_role === 'supplier' || target.warehouse) && !target.taken.includes(form.data.inviter_role));
    const [find, setFind] = useState('');
    const needle = find.trim().toLowerCase();
    const shown = invitable.filter((target) => `${target.organization} ${target.name}`.toLowerCase().includes(needle));
    const organizations = [...new Map(shown.map((target) => [target.organization_id, target.organization])).entries()];
    const chosen = (ids: string[]) => form.data.target_units.filter((ref) => !ids.includes(ref));
    const hasWarehouse = units.some((unit) => unit.warehouse);
    const selectable = (unit: Props['units'][number]) => form.data.inviter_role === 'buyer' || unit.warehouse;

    return (
        <form
            className="space-y-3"
            onSubmit={(e) => {
                e.preventDefault();
                form.post('/trade/partners/invitations', { preserveScroll: true, onSuccess: () => form.reset('target_units', 'message') });
            }}
        >
            <div className="flex flex-wrap gap-4 text-sm">
                <label className="flex items-center gap-1">
                    <input type="radio" disabled={!hasWarehouse} checked={form.data.inviter_role === 'supplier'} onChange={() => form.setData({ ...form.data, inviter_role: 'supplier', target_units: [] as string[], units: only(units, 'supplier') })} />
                    Moja organizacja przedstawia oferty (hurtownie – zapraszam sklepy i hurtownie)
                </label>
                <label className="flex items-center gap-1">
                    <input type="radio" checked={form.data.inviter_role === 'buyer'} onChange={() => form.setData({ ...form.data, inviter_role: 'buyer', target_units: [] as string[], units: only(units, 'buyer') })} />
                    Moja organizacja zamawia (zapraszam hurtownie)
                </label>
            </div>
            <fieldset className="text-sm">
                <legend className="font-semibold">Kogo zapraszasz (każda zaznaczona jednostka dostaje osobne zaproszenie)</legend>
                <input className={`${inputCls} mb-1`} placeholder="Szukaj jednostki…" value={find} onChange={(e) => setFind(e.target.value)} />
                <div className="mb-1 flex gap-3 text-xs text-[#007bff]">
                    <button type="button" className="hover:underline" onClick={() => form.setData('target_units', [...new Set([...form.data.target_units, ...shown.map((target) => target.ref)])])}>
                        zaznacz wszystkie{find ? ' widoczne' : ''}
                    </button>
                    <button type="button" className="hover:underline" onClick={() => form.setData('target_units', [])}>
                        odznacz wszystkie
                    </button>
                    <span className="text-[#6c757d]">Wybrano: {form.data.target_units.length}</span>
                </div>
                <div className="grid max-h-60 gap-1 overflow-y-auto rounded border border-[#dee2e6] p-2">
                    {invitable.length === 0 && <Empty>Brak jednostek, które można zaprosić.</Empty>}
                    {organizations.map(([organizationId, organization]) => {
                        const own = shown.filter((target) => target.organization_id === organizationId);
                        const refs = own.map((target) => target.ref);
                        const all = refs.every((ref) => form.data.target_units.includes(ref));

                        return (
                            <div key={organizationId} className="rounded border border-[#f1f3f5] p-1.5">
                                <label className="flex items-center gap-2 font-semibold">
                                    <input type="checkbox" checked={all} onChange={(e) => form.setData('target_units', e.target.checked ? [...new Set([...form.data.target_units, ...refs])] : chosen(refs))} />
                                    {organization} <span className="text-xs font-normal text-[#6c757d]">(cała organizacja: {form.data.inviter_role === 'supplier' ? 'wszystkie jednostki' : 'wszystkie hurtownie'})</span>
                                </label>
                                <div className="ml-6 grid gap-0.5">
                                    {own.map((target) => (
                                        <label key={target.ref} className="flex items-center gap-2">
                                            <input
                                                type="checkbox"
                                                checked={form.data.target_units.includes(target.ref)}
                                                onChange={(e) => form.setData('target_units', e.target.checked ? [...form.data.target_units, target.ref] : form.data.target_units.filter((ref) => ref !== target.ref))}
                                            />
                                            {target.name} <span className="text-xs text-[#6c757d]">{target.warehouse ? 'hurtownia' : 'sklep'}</span>
                                        </label>
                                    ))}
                                </div>
                            </div>
                        );
                    })}
                </div>
            </fieldset>
            <fieldset className="text-sm">
                <legend className="font-semibold">Moje jednostki objęte współpracą</legend>
                {units.filter(selectable).length === 0 && <Empty>Nie masz jednostek, które mogą wystąpić w tej roli.</Empty>}
                {units.map((unit) => (
                    <label key={unit.ref} className="flex items-center gap-2">
                        <input
                            type="checkbox"
                            disabled={form.data.all_current || !selectable(unit)}
                            checked={selectable(unit) && form.data.units.includes(unit.ref)}
                            onChange={(e) => form.setData('units', e.target.checked ? [...form.data.units, unit.ref] : form.data.units.filter((ref) => ref !== unit.ref))}
                        />
                        <span className={selectable(unit) ? '' : 'text-[#adb5bd]'}>{unit.name}</span>
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
            <button type="submit" className={btn} disabled={form.processing || form.data.target_units.length === 0 || (form.data.units.length === 0 && !form.data.all_current)}>
                Wyślij zaproszenie
            </button>
            <p className="text-xs text-[#6c757d]">
                Zaproszenie dociera tylko do organizacji, która istnieje w systemie. Odpowiedź jest taka sama, gdy adres jest nieznany. Odbiorca wybiera swoje jednostki
                przy akceptacji, a link nic nie akceptuje bez zalogowania osoby reprezentującej organizację.
            </p>
        </form>
    );
}

export default function PartnersIndex({ relationships, sent, can_invite, is_owner, units, colleagues, targets, own }: Props) {
    return (
        <>
            <Head title="Współpraca handlowa" />
            <ContentHeader title="Współpraca handlowa" crumbs={[{ label: 'Handel', href: '/trade/available' }, { label: 'Współpraca' }]} />
            <Content>
                <TradeTabs current="partners" />
                <Errors />
                <div className="space-y-4">
                    <Card title="Moja organizacja – współpraca automatyczna" color="success">
                        <p className="mb-2 text-sm">
                            Jednostki Twojej organizacji współpracują bez zaproszeń i potwierdzeń: sklepy zamawiają w hurtowniach organizacji, a hurtownie przedstawiają oferty oraz zamawiają u innych hurtowni, także własnych.
                        </p>
                        <div className="grid gap-3 text-sm md:grid-cols-2">
                            <div>
                                <p className="font-semibold">Hurtownie (oferują i zamawiają)</p>
                                {own.warehouses.length === 0 ? <Empty>Brak hurtowni.</Empty> : <ul>{own.warehouses.map((name) => <li key={name}>{name}</li>)}</ul>}
                            </div>
                            <div>
                                <p className="font-semibold">Sklepy (zamawiają)</p>
                                {own.shops.length === 0 ? <Empty>Brak sklepów.</Empty> : <ul>{own.shops.map((name) => <li key={name}>{name}</li>)}</ul>}
                            </div>
                        </div>
                    </Card>

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

                    {(colleagues.can_invite || colleagues.received.length > 0 || colleagues.consents.length > 0) && (
                        <Card title="Zaproś osobę z mojej organizacji" color="warning">
                            <ColleagueInvitations data={colleagues} />
                        </Card>
                    )}

                    {can_invite && (
                        <Card title="Zaproś do współpracy" color="success">
                            <InviteForm units={units} isOwner={is_owner} targets={targets} />
                        </Card>
                    )}
                </div>
            </Content>
        </>
    );
}
