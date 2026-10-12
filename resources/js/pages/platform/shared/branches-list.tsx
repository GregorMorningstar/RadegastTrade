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

export type BranchesPageProps = {
    /** 'shop' lub 'warehouse': adresy panelu kierownika */
    group: 'shop' | 'warehouse';
    headquarter: { id: number; name: string };
    newInvitations: Invitation[];
    branches: {
        id: number;
        name: string;
        barcode: string | null;
        NIP: string | null;
        address: string;
        managers: { id: number; name: string; email: string }[];
    }[];
};

/** Placówki sklepu albo hurtowni i zaproszenia ich kierowników (panel kierownika siedziby). */
export default function BranchesPage({ group, headquarter, newInvitations, branches }: BranchesPageProps) {
    const base = `/${group}/owner`;
    const form = useForm({ email: '', phone: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(`${base}/branches/invitations`, {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    };

    const cancel = (id: number) => {
        if (confirm('Anulować to zaproszenie?')) {
            router.delete(`${base}/branches/invitations/${id}`, { preserveScroll: true });
        }
    };

    return (
        <>
            <Head title="Placówki" />
            <ContentHeader
                title="Placówki"
                crumbs={[{ label: 'Panel', href: base }, { label: 'Placówki' }]}
            />
            <Content>
                <Card title="Zaproś kierownika nowej placówki" color="success" className="mb-4">
                    <p className="mb-3 text-sm text-[#6c757d]">
                        Zaproszony kierownik założy placówkę w siedzibie <strong>{headquarter.name}</strong> po
                        przyjęciu zaproszenia i poda jej nazwę oraz NIP. Zaproszenie jest powiązane z Twoją
                        organizacją.
                    </p>
                    <form onSubmit={submit} className="flex flex-wrap items-start gap-2">
                        <div>
                            <input
                                type="email"
                                value={form.data.email}
                                onChange={(e) => form.setData('email', e.target.value)}
                                placeholder="E-mail kierownika placówki"
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

                    {newInvitations.length > 0 && (
                        <ul className="mt-3 space-y-1 text-sm">
                            {newInvitations.map((invitation) => (
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
                    )}
                </Card>

                {branches.length === 0 && (
                    <Card title="Placówki" color="primary">
                        Nie ma jeszcze żadnej placówki. Zaproś kierownika, który ją założy.
                    </Card>
                )}

                <div className="space-y-4">
                    {branches.map((branch) => (
                        <Card key={branch.id} title={branch.name} color="primary">
                            <div className="flex flex-wrap items-start justify-between gap-4">
                                <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
                                    <dt className="text-[#6c757d]">NIP</dt>
                                    <dd>{branch.NIP ?? '—'}</dd>
                                    <dt className="text-[#6c757d]">Adres</dt>
                                    <dd>{branch.address || '—'}</dd>
                                    <dt className="text-[#6c757d]">Kierownicy</dt>
                                    <dd>
                                        {branch.managers.length === 0
                                            ? '—'
                                            : branch.managers.map((m) => `${m.name} (${m.email})`).join(', ')}
                                    </dd>
                                </dl>

                                {branch.barcode && (
                                    <div title={`Kod placówki: ${branch.barcode}`}>
                                        <Barcode
                                            value={branch.barcode}
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
                        </Card>
                    ))}
                </div>
            </Content>
        </>
    );
}
