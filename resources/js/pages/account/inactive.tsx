import { Head, router } from '@inertiajs/react';
import { LogOut, ShieldOff } from 'lucide-react';
import ChatBox from '@/components/chat/chat-box';
import { useChat } from '@/components/chat/use-chat';

type Props = {
    /** Who switched the account off (null when that person no longer exists) */
    deactivatedBy: { id: number; name: string; role: string } | null;
    /** When it was switched off, e.g. 15.10.2026 16:42 */
    deactivatedAt: string | null;
};

function InactiveChat() {
    const chat = useChat(true);

    return <ChatBox chat={chat} variant="page" />;
}

/**
 * The page of a deactivated account: the account was switched off by someone, and the only things left are
 * the chat with that person and with the administrators, and logging out.
 */
export default function AccountInactive({ deactivatedBy, deactivatedAt }: Props) {
    return (
        <>
            <Head title="Konto nieaktywne" />

            <div className="mx-auto max-w-4xl space-y-4 px-4 py-8">
                <section className="rounded-lg border border-[#f1aeb5] bg-white p-6 text-center shadow-sm">
                    <ShieldOff className="mx-auto size-10 text-[#dc3545]" />
                    <h1 className="mt-3 text-xl font-semibold">Konto nie jest aktywne</h1>
                    <p className="mt-2 text-sm text-[#495057]">
                        Twoje konto zostało wyłączone
                        {deactivatedBy && (
                            <>
                                {' '}
                                przez <strong>{deactivatedBy.name}</strong> ({deactivatedBy.role})
                            </>
                        )}
                        {deactivatedAt && (
                            <>
                                {' '}
                                dnia <strong>{deactivatedAt}</strong>
                            </>
                        )}
                        .
                    </p>
                    <p className="mt-1 text-sm text-[#6c757d]">
                        Nie masz dostępu do panelu. Możesz napisać na czacie do osoby, która wyłączyła konto, albo do administratora.
                    </p>

                    <button
                        type="button"
                        onClick={() => router.post('/logout')}
                        className="mt-4 inline-flex items-center gap-2 rounded border border-[#ced4da] px-4 py-1.5 text-sm hover:bg-[#f8f9fa]"
                    >
                        <LogOut className="size-4" />
                        Wyloguj
                    </button>
                </section>

                <InactiveChat />
            </div>
        </>
    );
}
