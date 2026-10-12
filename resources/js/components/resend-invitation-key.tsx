import { router } from '@inertiajs/react';
import { RefreshCw } from 'lucide-react';
import { useState } from 'react';

type Props = {
    /** Adres, który wysyła nowy klucz na e-mail z zaproszenia */
    url: string;
};

/** Przycisk „Wyślij nowy klucz”: stary klucz przestaje działać, a nowy trafia na adres z zaproszenia. */
export default function ResendInvitationKey({ url }: Props) {
    const [processing, setProcessing] = useState(false);
    const [message, setMessage] = useState<{ text: string; error: boolean } | null>(null);

    const resend = () => {
        setProcessing(true);
        setMessage(null);

        router.post(
            url,
            {},
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () =>
                    setMessage({
                        text: 'Nowy klucz został wysłany na adres z zaproszenia.',
                        error: false,
                    }),
                onError: (errors) =>
                    setMessage({
                        text: errors.resend ?? 'Nie udało się wysłać nowego klucza.',
                        error: true,
                    }),
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <div className="space-y-1">
            <button
                type="button"
                onClick={resend}
                disabled={processing}
                className="inline-flex items-center gap-1.5 text-sm text-muted-foreground underline-offset-4 hover:text-foreground hover:underline disabled:opacity-60"
            >
                <RefreshCw className={processing ? 'size-3.5 animate-spin' : 'size-3.5'} />
                Nie masz klucza? Wyślij nowy
            </button>
            {message && (
                <p
                    role="status"
                    className={message.error ? 'text-xs text-red-600' : 'text-xs text-emerald-600'}
                >
                    {message.text}
                </p>
            )}
        </div>
    );
}
