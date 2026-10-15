import { Head, router } from '@inertiajs/react';
import { LogOut, MailCheck } from 'lucide-react';

/** The page of an account without a panel: it waits for an invitation that gives it a role in an organization. */
export default function AccountPending({ email }: { email: string }) {
    return (
        <>
            <Head title="Konto bez panelu" />

            <div className="mx-auto max-w-xl px-4 py-16">
                <section className="rounded-lg border border-[#dee2e6] bg-white p-6 text-center shadow-sm">
                    <MailCheck className="mx-auto size-10 text-[#007bff]" />
                    <h1 className="mt-3 text-xl font-semibold">Twoje konto nie ma jeszcze panelu</h1>
                    <p className="mt-2 text-sm text-[#495057]">
                        Konto <strong>{email}</strong> nie ma roli w żadnej organizacji. Panel dostaniesz po przyjęciu zaproszenia:
                        administrator zaprasza organizacje, organizacja główne siedziby, siedziby placówki, a placówki pracowników.
                    </p>
                    <p className="mt-1 text-sm text-[#6c757d]">Sprawdź skrzynkę e-mail: zaproszenie zawiera link i 6-cyfrowy klucz.</p>

                    <button
                        type="button"
                        onClick={() => router.post('/logout')}
                        className="mt-4 inline-flex items-center gap-2 rounded border border-[#ced4da] px-4 py-1.5 text-sm hover:bg-[#f8f9fa]"
                    >
                        <LogOut className="size-4" />
                        Wyloguj
                    </button>
                </section>
            </div>
        </>
    );
}
