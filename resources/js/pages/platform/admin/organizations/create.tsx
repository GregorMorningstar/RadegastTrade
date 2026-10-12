import { Head, Link, useForm } from '@inertiajs/react';
import { Send } from 'lucide-react';
import type { FormEvent } from 'react';
import { Card, Content, ContentHeader } from '@/components/adminlte/blocks';

export default function OrganizationCreate() {
    const form = useForm({ email: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post('/platform/admin/organizations/invitations');
    };

    return (
        <>
            <Head title="Dodaj organizację" />
            <ContentHeader
                title="Dodaj organizację"
                crumbs={[
                    { label: 'Platforma', href: '/platform/admin' },
                    { label: 'Organizacje', href: '/platform/admin/organizations' },
                    { label: 'Dodaj' },
                ]}
            />
            <Content>
                <Card title="Zaproś właściciela nowej organizacji" color="success">
                    <p className="mb-4 text-sm text-[#6c757d]">
                        Podaj adres e-mail przyszłego właściciela. Wiadomość
                        zawiera link i 6-cyfrowy klucz. Nazwę organizacji i jej
                        dane (NIP, adres, także pobrane z rejestru po NIP)
                        właściciel poda sam po przyjęciu zaproszenia, a
                        organizacja dostanie swój kod kreskowy.
                    </p>

                    <form onSubmit={submit} className="space-y-4">
                        <div className="max-w-md">
                            <label htmlFor="email" className="mb-1 block text-sm font-medium">
                                E-mail właściciela <span className="text-[#dc3545]">*</span>
                            </label>
                            <input
                                id="email"
                                type="email"
                                value={form.data.email}
                                onChange={(e) => form.setData('email', e.target.value)}
                                required
                                autoFocus
                                className="w-full rounded border border-[#ced4da] px-3 py-1.5 text-sm"
                            />
                            {form.errors.email && (
                                <p className="mt-1 text-xs text-[#dc3545]">{form.errors.email}</p>
                            )}
                        </div>

                        <div className="flex items-center gap-3 border-t border-[#dee2e6] pt-4">
                            <button
                                type="submit"
                                disabled={form.processing}
                                className="flex items-center gap-1 rounded bg-[#28a745] px-4 py-1.5 text-sm text-white hover:bg-[#218838] disabled:opacity-60"
                            >
                                <Send className="size-4" />
                                Wyślij zaproszenie
                            </button>
                            <Link
                                href="/platform/admin/organizations"
                                className="text-sm text-[#6c757d] hover:underline"
                            >
                                Anuluj
                            </Link>
                        </div>
                    </form>
                </Card>
            </Content>
        </>
    );
}
