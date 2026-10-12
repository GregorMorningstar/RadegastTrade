import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import ResendInvitationKey from '@/components/resend-invitation-key';
import { Spinner } from '@/components/ui/spinner';

type Props = {
    organizationName: string | null;
    email: string;
    acceptUrl: string;
    resendUrl: string;
    status:
        | 'ready'
        | 'accepted'
        | 'revoked'
        | 'expired'
        | 'locked'
        | 'wrong_account';
};

const messages: Record<Exclude<Props['status'], 'ready'>, string> = {
    accepted: 'To zaproszenie zostało już przyjęte.',
    revoked: 'To zaproszenie zostało wycofane. Poproś administratora o nowe.',
    expired: 'To zaproszenie wygasło. Poproś administratora o nowe.',
    locked: 'To zaproszenie zostało zablokowane po zbyt wielu błędnych próbach. Poproś administratora o nowe.',
    wrong_account:
        'To zaproszenie jest przeznaczone dla innego adresu e-mail. Zaloguj się na konto z adresem, na który je wysłano.',
};

export default function OrganizationInvitation({
    organizationName,
    email,
    acceptUrl,
    resendUrl,
    status,
}: Props) {
    const form = useForm({ code: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(acceptUrl);
    };

    return (
        <>
            <Head title="Zaproszenie do organizacji" />

            {status !== 'ready' ? (
                <p className="text-center text-sm text-muted-foreground">
                    {messages[status]}
                </p>
            ) : (
                <form onSubmit={submit} className="space-y-6">
                    <p className="text-center text-sm text-muted-foreground">
                        Zaproszenie dla <strong>{email}</strong>. Wpisz 6-cyfrowy
                        kod z wiadomości e-mail.
                    </p>

                    <div className="grid gap-2">
                        <Label htmlFor="code">Kod weryfikacyjny</Label>
                        <Input
                            id="code"
                            name="code"
                            inputMode="numeric"
                            autoComplete="one-time-code"
                            maxLength={6}
                            placeholder="000000"
                            value={form.data.code}
                            onChange={(e) =>
                                form.setData('code', e.target.value.replace(/\D/g, ''))
                            }
                            autoFocus
                        />
                        <InputError message={form.errors.code} />
                    </div>

                    <ResendInvitationKey url={resendUrl} />

                    <Button className="w-full" disabled={form.processing}>
                        {form.processing && <Spinner />}
                        Przyjmij zaproszenie
                    </Button>
                </form>
            )}
        </>
    );
}

OrganizationInvitation.layout = (props: { organizationName?: string | null }) => ({
    title: props.organizationName
        ? `Organizacja ${props.organizationName}`
        : 'Nowa organizacja',
    description: 'Zostałeś zaproszony jako właściciel organizacji',
});
