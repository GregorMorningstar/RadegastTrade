import { Head, router, useForm } from '@inertiajs/react';
import { Send, Trash2 } from 'lucide-react';
import type { FormEvent } from 'react';
import { Card, Content, ContentHeader } from '@/components/adminlte/blocks';

type Props = {
    /** 'shop' lub 'warehouse': adresy panelu kierownika */
    group: 'shop' | 'warehouse';
    branch: { id: number; name: string };
    workers: { id: number; name: string; email: string; phone: string | null; last_login: string | null }[];
    invitations: {
        id: number;
        email: string;
        phone: string | null;
        expires_at: string;
        locked: boolean;
    }[];
};

/** Pracownicy placówki i zaproszenia (panel kierownika placówki sklepu lub hurtowni). */
export default function WorkersPage({ group, branch, workers, invitations }: Props) {
    const base = `/${group}/leader`;
    const form = useForm({ email: '', phone: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(`${base}/workers/invitations`, {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    };

    const cancel = (id: number) => {
        if (confirm('Anulować to zaproszenie?')) {
            router.delete(`${base}/workers/invitations/${id}`, { preserveScroll: true });
        }
    };

    return (
        <>
            <Head title="Pracownicy" />
            <ContentHeader
                title="Pracownicy"
                crumbs={[{ label: 'Panel', href: base }, { label: 'Pracownicy' }]}
            />
            <Content>
                <Card title="Zaproś pracownika" color="success" className="mb-4">
                    <p className="mb-3 text-sm text-[#6c757d]">
                        Zaproszony pracownik zostanie przypisany do placówki <strong>{branch.name}</strong> i jej
                        organizacji po przyjęciu zaproszenia.
                    </p>
                    <form onSubmit={submit} className="flex flex-wrap items-start gap-2">
                        <div>
                            <input
                                type="email"
                                value={form.data.email}
                                onChange={(e) => form.setData('email', e.target.value)}
                                placeholder="E-mail pracownika"
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

                    {invitations.length > 0 && (
                        <ul className="mt-3 space-y-1 text-sm">
                            {invitations.map((invitation) => (
                                <li key={invitation.id} className="flex items-center gap-3">
                                    <span>{invitation.email}</span>
                                    <span className="text-[#6c757d]">{invitation.phone}</span>
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

                <Card title={`Pracownicy (${workers.length})`} color="primary">
                    {workers.length === 0 ? (
                        <p className="text-sm text-[#6c757d]">Nie ma jeszcze żadnych pracowników.</p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-sm">
                                <thead>
                                    <tr className="border-b border-[#dee2e6]">
                                        <th className="py-2 pr-4 font-semibold">Nazwa</th>
                                        <th className="py-2 pr-4 font-semibold">E-mail</th>
                                        <th className="py-2 pr-4 font-semibold">Telefon</th>
                                        <th className="py-2 font-semibold">Ostatnie logowanie</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {workers.map((worker) => (
                                        <tr key={worker.id} className="border-b border-[#dee2e6] last:border-0">
                                            <td className="py-2 pr-4">{worker.name}</td>
                                            <td className="py-2 pr-4">{worker.email}</td>
                                            <td className="py-2 pr-4">{worker.phone ?? '—'}</td>
                                            <td className="py-2">{worker.last_login ?? '—'}</td>
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
