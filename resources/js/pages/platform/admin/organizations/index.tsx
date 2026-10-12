import { Head, router, useForm } from '@inertiajs/react';
import { Send, Trash2 } from 'lucide-react';
import Barcode from 'react-barcode';
import type { FormEvent } from 'react';
import { Card, Content, ContentHeader } from '@/components/adminlte/blocks';
import OrganizationTopMenuComponent from '@/pages/platform/admin/components/OrganizationTopMenuComponent';

type Invitation = {
    id: number;
    email: string;
    expires_at: string;
    locked: boolean;
};

type Organization = {
    id: number;
    name: string;
    NIP: string | null;
    barcode: string | null;
    owners: { id: number; name: string; email: string }[];
    invitations: Invitation[];
};

function InviteForm({ organization }: { organization: Organization }) {
    const form = useForm({ email: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(`/platform/admin/organizations/${organization.id}/invitations`, {
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
                    placeholder="E-mail właściciela"
                    required
                    className="w-64 rounded border border-[#ced4da] px-3 py-1.5 text-sm"
                />
                {form.errors.email && (
                    <p className="mt-1 text-xs text-[#dc3545]">{form.errors.email}</p>
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

export default function OrganizationIndex({
    organizations,
    newOrganizationInvitations,
}: {
    organizations: Organization[];
    newOrganizationInvitations: Invitation[];
}) {
    const cancel = (id: number) => {
        if (confirm('Anulować to zaproszenie?')) {
            router.delete(`/platform/admin/organizations/invitations/${id}`, {
                preserveScroll: true,
            });
        }
    };

    return (
        <>
            <Head title="Organizacje" />
            <ContentHeader
                title="Organizacje"
                crumbs={[
                    { label: 'Platforma', href: '/platform/admin' },
                    { label: 'Organizacje' },
                ]}
            />
            <Content>
                <div className="mb-3">
                    <OrganizationTopMenuComponent />
                </div>

                {newOrganizationInvitations.length > 0 && (
                    <Card
                        title="Oczekujące zaproszenia nowych organizacji"
                        color="warning"
                        className="mb-4"
                    >
                        <ul className="space-y-1 text-sm">
                            {newOrganizationInvitations.map((invitation) => (
                                <li key={invitation.id} className="flex items-center gap-3">
                                    <span>{invitation.email}</span>
                                    <span className="text-[#6c757d]">
                                        ważne do {invitation.expires_at}
                                    </span>
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
                    </Card>
                )}

                {organizations.length === 0 && (
                    <Card title="Organizacje" color="primary">
                        Nie ma jeszcze żadnych organizacji. Użyj przycisku „Dodaj
                        organizację”, aby zaprosić jej właściciela.
                    </Card>
                )}

                <div className="space-y-4">
                    {organizations.map((organization) => (
                        <Card
                            key={organization.id}
                            title={organization.name}
                            color="primary"
                        >
                            <div className="flex flex-wrap items-start justify-between gap-4">
                                <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
                                    <dt className="text-[#6c757d]">NIP</dt>
                                    <dd>{organization.NIP ?? '—'}</dd>
                                    <dt className="text-[#6c757d]">Właściciele</dt>
                                    <dd>
                                        {organization.owners.length === 0
                                            ? '—'
                                            : organization.owners
                                                  .map((o) => `${o.name} (${o.email})`)
                                                  .join(', ')}
                                    </dd>
                                </dl>

                                {organization.barcode && (
                                    <div title={`Kod organizacji: ${organization.barcode}`}>
                                        <Barcode
                                            value={organization.barcode}
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
                                <h4 className="mb-2 text-sm font-semibold">
                                    Zaproś właściciela organizacji
                                </h4>
                                <InviteForm organization={organization} />

                                {organization.invitations.length > 0 && (
                                    <ul className="mt-3 space-y-1 text-sm">
                                        {organization.invitations.map((invitation) => (
                                            <li
                                                key={invitation.id}
                                                className="flex items-center gap-3"
                                            >
                                                <span>{invitation.email}</span>
                                                <span className="text-[#6c757d]">
                                                    ważne do {invitation.expires_at}
                                                </span>
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
                            </div>
                        </Card>
                    ))}
                </div>
            </Content>
        </>
    );
}
