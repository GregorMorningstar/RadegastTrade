import { Head, router, useForm } from '@inertiajs/react';
import { Send, Trash2 } from 'lucide-react';
import type { FormEvent } from 'react';
import Barcode from 'react-barcode';
import { Card, Content, ContentHeader } from '@/components/adminlte/blocks';

type Invitation = {
    id: number;
    email: string;
    expires_at: string;
    locked: boolean;
};

type Headquarter = {
    id: number;
    name: string;
    barcode: string | null;
    NIP: string | null;
    ownNip: boolean;
    address: string;
    owners: { id: number; name: string; email: string }[];
    invitations: Invitation[];
};

type Props = {
    type: 'shops' | 'warehouses';
    newInvitations: Invitation[];
    headquarters: Headquarter[];
};

const texts = {
    shops: {
        title: 'Sklepy',
        singular: 'sklepu',
        empty: 'Nie ma jeszcze żadnej siedziby sklepu. Zaproś kierownika, który ją założy.',
    },
    warehouses: {
        title: 'Hurtownie',
        singular: 'hurtowni',
        empty: 'Nie ma jeszcze żadnej siedziby hurtowni. Zaproś kierownika, który ją założy.',
    },
};

const inputClass = 'w-full rounded border border-[#ced4da] px-3 py-1.5 text-sm';

function InviteForm({ type, headquarter }: { type: Props['type']; headquarter?: Headquarter }) {
    const form = useForm({ email: '', phone: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(`/platform/organization/${type}${headquarter ? `/${headquarter.id}` : ''}/invitations`, {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    };

    return (
        <form onSubmit={submit} className="flex flex-wrap items-start gap-2">
            <div>
                <input
                    type="email"
                    value={form.data.email}
                    onChange={(e) => form.setData('email', e.target.value)}
                    placeholder="E-mail kierownika"
                    required
                    className="w-64 rounded border border-[#ced4da] px-3 py-1.5 text-sm"
                />
                {form.errors.email && (
                    <p className="mt-1 text-xs text-[#dc3545]">{form.errors.email}</p>
                )}
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
                {form.errors.phone && (
                    <p className="mt-1 text-xs text-[#dc3545]">{form.errors.phone}</p>
                )}
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
    );
}

export default function HeadquartersPage({ type, newInvitations, headquarters }: Props) {
    const text = texts[type];

    const cancel = (id: number) => {
        if (confirm('Anulować to zaproszenie?')) {
            router.delete(`/platform/organization/invitations/${id}`, { preserveScroll: true });
        }
    };

    const pendingList = (invitations: Invitation[], className = 'mt-3') =>
        invitations.length > 0 && (
            <ul className={`${className} space-y-1 text-sm`}>
                {invitations.map((invitation) => (
                    <li key={invitation.id} className="flex items-center gap-3">
                        <span>{invitation.email}</span>
                        <span className="text-[#6c757d]">ważne do {invitation.expires_at}</span>
                        {invitation.locked && (
                            <span className="rounded bg-[#dc3545] px-2 py-0.5 text-xs text-white">
                                zablokowane
                            </span>
                        )}
                        <button
                            type="button"
                            onClick={() => cancel(invitation.id)}
                            className="text-[#dc3545] hover:underline"
                            title="Anuluj zaproszenie"
                        >
                            <Trash2 className="size-4" />
                        </button>
                    </li>
                ))}
            </ul>
        );

    return (
        <>
            <Head title={text.title} />
            <ContentHeader
                title={text.title}
                crumbs={[
                    { label: 'Platforma', href: '/platform/organization' },
                    { label: text.title },
                ]}
            />
            <Content>
                <Card title={`Zaproś kierownika nowej siedziby ${text.singular}`} color="success" className="mb-4">
                    <p className="mb-3 text-sm text-[#6c757d]">
                        Zaproszony kierownik założy siedzibę po przyjęciu zaproszenia i poda jej
                        nazwę oraz NIP (taki sam jak NIP organizacji albo inny).
                    </p>
                    <InviteForm type={type} />
                    {pendingList(newInvitations)}
                </Card>

                {headquarters.length === 0 && (
                    <Card title={text.title} color="primary">
                        {text.empty}
                    </Card>
                )}

                <div className="space-y-4">
                    {headquarters.map((headquarter) => (
                        <Card key={headquarter.id} title={headquarter.name} color="primary">
                            <div className="flex flex-wrap items-start justify-between gap-4">
                                <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
                                    <dt className="text-[#6c757d]">NIP</dt>
                                    <dd>
                                        {headquarter.NIP ?? '—'}
                                        {headquarter.NIP && !headquarter.ownNip && (
                                            <span className="text-[#6c757d]"> (NIP organizacji)</span>
                                        )}
                                    </dd>
                                    <dt className="text-[#6c757d]">Adres</dt>
                                    <dd>{headquarter.address || '—'}</dd>
                                    <dt className="text-[#6c757d]">Kierownicy</dt>
                                    <dd>
                                        {headquarter.owners.length === 0
                                            ? '—'
                                            : headquarter.owners
                                                  .map((o) => `${o.name} (${o.email})`)
                                                  .join(', ')}
                                    </dd>
                                </dl>

                                {headquarter.barcode && (
                                    <div title={`Kod siedziby: ${headquarter.barcode}`}>
                                        <Barcode
                                            value={headquarter.barcode}
                                            format="EAN13"
                                            width={1.4}
                                            height={40}
                                            fontSize={12}
                                            margin={0}
                                            background="transparent"
                                        />
                                    </div>
                                )}
                            </div>

                            <div className="mt-4 border-t border-[#dee2e6] pt-4">
                                <h4 className="mb-2 text-sm font-semibold">Zaproś kierownika siedziby</h4>
                                <InviteForm type={type} headquarter={headquarter} />

                                {pendingList(headquarter.invitations)}
                            </div>
                        </Card>
                    ))}
                </div>
            </Content>
        </>
    );
}
