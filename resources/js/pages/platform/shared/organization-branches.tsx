import { Head, router, useForm } from '@inertiajs/react';
import { Send, Trash2 } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import { Card, Content, ContentHeader } from '@/components/adminlte/blocks';
import HeadquartersTree from '@/pages/platform/admin/components/HeadquartersTree';

type Invitation = {
    id: number;
    email: string;
    phone: string | null;
    headquarter: string | null;
    expires_at: string;
    locked: boolean;
};

type StandaloneBranch = {
    id: number;
    name: string;
    barcode: string | null;
    people: { id: number; name: string; email: string; role: string }[];
};

type Props = {
    type: 'shops' | 'warehouses';
    headquarters: { id: number; name: string }[];
    standalone: StandaloneBranch[];
    newInvitations: Invitation[];
};

const texts = {
    shops: { title: 'Placówki sklepów', noun: 'sklepu', role: 'Kierownik placówki sklepu' },
    warehouses: { title: 'Placówki hurtowni', noun: 'hurtowni', role: 'Kierownik placówki hurtowni' },
};

/**
 * Branches of the shops or warehouses (organization owner): the owner invites the leader of a new branch in one of
 * the main headquarters (the invited person founds the branch after accepting), and sees the branches with their people.
 */
export default function OrganizationBranches({ type, headquarters, standalone, newInvitations }: Props) {
    const text = texts[type];
    const form = useForm({ email: '', phone: '' });
    const [headquarter, setHeadquarter] = useState('');

    const submit = (event: FormEvent) => {
        event.preventDefault();
        // no headquarters chosen: the shop or warehouse is founded straight under the organization
        const url = headquarter === 'standalone'
            ? `/platform/organization/${type}/branches/standalone-invitations`
            : `/platform/organization/${type}/${headquarter}/branches/invitations`;

        form.post(url, {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    };

    const cancel = (id: number) => {
        if (confirm('Anulować to zaproszenie?')) {
            router.delete(`/platform/organization/invitations/${id}`, { preserveScroll: true });
        }
    };

    return (
        <>
            <Head title={text.title} />
            <ContentHeader title={text.title} crumbs={[{ label: 'Platforma', href: '/platform/organization' }, { label: text.title }]} />
            <Content>
                <Card title={`Zaproś kierownika nowej placówki ${text.noun}`} color="success" className="mb-4">
                    <p className="mb-3 text-sm text-[#6c757d]">
                        Wybierz główną siedzibę, do której należy placówka, albo „bez siedziby”, żeby założyć ją bezpośrednio pod organizacją, i podaj e-mail oraz telefon kierownika. Zaproszona osoba dostaje rolę{' '}
                        <strong>{text.role}</strong>; po przyjęciu zaproszenia zakłada placówkę i podaje jej nazwę oraz NIP. Placówkę bez siedziby można później dołączyć do siedziby.
                    </p>

                    {(
                        <form onSubmit={submit} className="flex flex-wrap items-start gap-2">
                            <select
                                value={headquarter}
                                onChange={(e) => setHeadquarter(e.target.value)}
                                required
                                aria-label="Główna siedziba placówki"
                                className="max-w-64 rounded border border-[#ced4da] px-3 py-1.5 text-sm"
                            >
                                <option value="">— wybierz główną siedzibę —</option>
                                <option value="standalone">— bez siedziby (bezpośrednio pod organizacją) —</option>
                                {headquarters.map((item) => (
                                    <option key={item.id} value={item.id}>
                                        {item.name}
                                    </option>
                                ))}
                            </select>
                            <div>
                                <input
                                    type="email"
                                    value={form.data.email}
                                    onChange={(e) => form.setData('email', e.target.value)}
                                    placeholder="E-mail kierownika placówki"
                                    required
                                    className="w-64 rounded border border-[#ced4da] px-3 py-1.5 text-sm"
                                />
                                {form.errors.email && <p className="mt-1 text-xs text-[#dc3545]">{form.errors.email}</p>}
                            </div>
                            <div>
                                <input
                                    type="tel"
                                    value={form.data.phone}
                                    onChange={(e) => form.setData('phone', e.target.value)}
                                    placeholder="Telefon (np. +48 600 100 200)"
                                    required
                                    maxLength={20}
                                    className="w-56 rounded border border-[#ced4da] px-3 py-1.5 text-sm"
                                />
                                {form.errors.phone && <p className="mt-1 text-xs text-[#dc3545]">{form.errors.phone}</p>}
                            </div>
                            <button
                                type="submit"
                                disabled={form.processing}
                                className="flex items-center gap-1 rounded bg-[#007bff] px-3 py-1.5 text-sm text-white disabled:opacity-60"
                            >
                                <Send className="size-4" />
                                Wyślij zaproszenie
                            </button>
                        </form>
                    )}

                    {newInvitations.length > 0 && (
                        <ul className="mt-3 space-y-1 text-sm">
                            {newInvitations.map((invitation) => (
                                <li key={invitation.id} className="flex flex-wrap items-center gap-3">
                                    <span>{invitation.email}</span>
                                    {invitation.phone && <span className="text-[#6c757d]">{invitation.phone}</span>}
                                    {invitation.headquarter && <span className="rounded bg-[#e7f1ff] px-2 py-0.5 text-xs text-[#007bff]">{invitation.headquarter}</span>}
                                    <span className="text-[#6c757d]">ważne do {invitation.expires_at}</span>
                                    {invitation.locked && <span className="rounded bg-[#dc3545] px-2 py-0.5 text-xs text-white">zablokowane</span>}
                                    <button type="button" onClick={() => cancel(invitation.id)} className="text-[#dc3545] hover:underline" title="Anuluj zaproszenie">
                                        <Trash2 className="size-4" />
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>

                <StandalonePanel type={type} standalone={standalone} headquarters={headquarters} />

                <Card title={`Placówki ${text.noun} według głównych siedzib`} color="primary">
                    <HeadquartersTree url={`/platform/organization/${type}/tree`} type={type} content="branches" />
                </Card>
            </Content>
        </>
    );
}

/**
 * Branches founded straight under the organization (no main headquarters), each with its people. When a headquarters exists,
 * the owner attaches a branch to it: the branch, its people and its pending invitations become part of that headquarters.
 */
function StandalonePanel({ type, standalone, headquarters }: Pick<Props, 'type' | 'standalone' | 'headquarters'>) {
    const [target, setTarget] = useState<Record<number, string>>({});

    if (standalone.length === 0) {
        return null;
    }

    const attach = (branchId: number) => {
        const headquarterId = target[branchId];

        if (headquarterId && confirm('Dołączyć placówkę do wybranej siedziby? Jej osoby i zaproszenia przejdą do tej siedziby.')) {
            router.post(`/platform/organization/${type}/${headquarterId}/branches/attach`, { branches: [branchId] }, { preserveScroll: true });
        }
    };

    return (
        <Card title="Placówki bez siedziby" color="warning" className="mb-4">
            <ul className="space-y-3 text-sm">
                {standalone.map((branch) => (
                    <li key={branch.id} className="flex flex-wrap items-start justify-between gap-3 border-b border-[#dee2e6] pb-3 last:border-0 last:pb-0">
                        <div>
                            <p className="font-semibold">{branch.name}</p>
                            {branch.people.length === 0 ? (
                                <p className="text-[#6c757d]">Brak przypisanych osób.</p>
                            ) : (
                                <ul className="text-[#6c757d]">
                                    {branch.people.map((person) => (
                                        <li key={person.id}>
                                            {person.name} ({person.role}) – {person.email}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>
                        <div className="flex items-center gap-2">
                            <select
                                value={target[branch.id] ?? ''}
                                onChange={(e) => setTarget({ ...target, [branch.id]: e.target.value })}
                                aria-label={`Siedziba dla placówki ${branch.name}`}
                                className="max-w-56 rounded border border-[#ced4da] px-2 py-1.5 text-sm"
                            >
                                <option value="">— wybierz siedzibę —</option>
                                {headquarters.map((item) => (
                                    <option key={item.id} value={item.id}>
                                        {item.name}
                                    </option>
                                ))}
                            </select>
                            <button
                                type="button"
                                disabled={!target[branch.id]}
                                onClick={() => attach(branch.id)}
                                className="rounded border border-[#007bff] px-3 py-1.5 text-[#007bff] hover:bg-[#007bff] hover:text-white disabled:opacity-50"
                            >
                                Dołącz do siedziby
                            </button>
                        </div>
                    </li>
                ))}
            </ul>
        </Card>
    );
}
