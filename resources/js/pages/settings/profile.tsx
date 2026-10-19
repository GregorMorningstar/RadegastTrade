import { Form, Head, router, useForm, usePage } from '@inertiajs/react';
import { Link } from '@inertiajs/react';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import DeleteUser from '@/components/delete-user';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import UserAvatar from '@/components/user-avatar';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/profile';
import type { Auth } from '@/types';
import { send } from '@/routes/verification';

type PageProps = {
    auth: Auth;
};

function AvatarSection({ pending }: { pending: boolean }) {
    const { auth } = usePage<PageProps>().props;
    const form = useForm<{ avatar: File | null }>({ avatar: null });
    const codeForm = useForm<{ code: string }>({ code: '' });
    const [codeOpen, setCodeOpen] = useState(false);
    const preview = form.data.avatar ? URL.createObjectURL(form.data.avatar) : null;

    const confirm = () => {
        codeForm.post('/settings/profile/avatar/confirm', {
            preserveScroll: true,
            onSuccess: () => {
                codeForm.reset();
                setCodeOpen(false);
            },
        });
    };

    const upload = () => {
        form.post('/settings/profile/avatar', {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                codeForm.reset();
                setCodeOpen(true);
            },
        });
    };

    return (
        <div className="space-y-4">
            <Heading
                variant="small"
                title="Zdjęcie profilowe"
                description="Widoczne w menu, nagłówku i czacie. Bez zdjęcia pokazujemy domyślny awatar."
            />

            <div className="flex flex-wrap items-center gap-4">
                <UserAvatar src={preview ?? auth.user.avatar_url} name={auth.user.name} size={96} />

                <div className="space-y-2">
                    <input
                        type="file"
                        accept="image/jpeg,image/png,image/webp"
                        onChange={(e) => form.setData('avatar', e.target.files?.[0] ?? null)}
                        className="block text-sm"
                    />
                    <p className="text-xs text-muted-foreground">JPG, PNG lub WEBP, do 2 MB, od 64 do 4000 px.</p>
                    <InputError message={form.errors.avatar} />

                    {pending && (
                        <p className="text-sm">
                            Nowe zdjęcie czeka na potwierdzenie.{' '}
                            <button type="button" onClick={() => setCodeOpen(true)} className="text-primary underline">
                                Wpisz kod z e-maila
                            </button>
                        </p>
                    )}

                    <Dialog open={pending && codeOpen} onOpenChange={setCodeOpen}>
                        <DialogContent className="sm:max-w-sm">
                            <DialogHeader>
                                <DialogTitle>Potwierdź zmianę zdjęcia</DialogTitle>
                                <DialogDescription>
                                    Wysłaliśmy 6-cyfrowy kod na Twój e-mail. Jest ważny 15 minut.
                                </DialogDescription>
                            </DialogHeader>
                            <form
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    confirm();
                                }}
                                className="space-y-3"
                            >
                                <Input
                                    value={codeForm.data.code}
                                    onChange={(e) => codeForm.setData('code', e.target.value.replace(/\D/g, '').slice(0, 6))}
                                    inputMode="numeric"
                                    autoComplete="one-time-code"
                                    placeholder="000000"
                                    autoFocus
                                />
                                <InputError message={codeForm.errors.code} />
                                <div className="flex justify-end gap-2">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() =>
                                            router.delete('/settings/profile/avatar/pending', {
                                                preserveScroll: true,
                                                onSuccess: () => codeForm.reset(),
                                            })
                                        }
                                    >
                                        Anuluj zmianę
                                    </Button>
                                    <Button type="submit" disabled={codeForm.data.code.length !== 6 || codeForm.processing}>
                                        Potwierdź
                                    </Button>
                                </div>
                            </form>
                        </DialogContent>
                    </Dialog>

                    <div className="flex gap-2">
                        <Button type="button" onClick={upload} disabled={!form.data.avatar || form.processing}>
                            {pending ? 'Wyślij nowy kod' : 'Zapisz zdjęcie'}
                        </Button>
                        {auth.user.avatar_path && (
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => router.delete('/settings/profile/avatar', { preserveScroll: true })}
                            >
                                Usuń zdjęcie
                            </Button>
                        )}
                    </div>
                </div>
            </div>
        </div>
    );
}

export default function Profile({
    mustVerifyEmail,
    status,
    avatarPending,
}: {
    mustVerifyEmail: boolean;
    status?: string;
    avatarPending: boolean;
}) {
    const { auth } = usePage<PageProps>().props;

    return (
        <>
            <Head title="Profile settings" />

            <h1 className="sr-only">Profile settings</h1>

            <div className="space-y-6">
                <AvatarSection pending={avatarPending} />

                <Heading
                    variant="small"
                    title="Profile"
                    description="Update your name and email address"
                />

                <Form
                    {...ProfileController.update.form()}
                    options={{
                        preserveScroll: true,
                    }}
                    className="space-y-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="name">Name</Label>

                                <Input
                                    id="name"
                                    className="mt-1 block w-full"
                                    defaultValue={auth.user.name}
                                    name="name"
                                    required
                                    autoComplete="name"
                                    placeholder="Full name"
                                />

                                <InputError
                                    className="mt-2"
                                    message={errors.name}
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="email">Email address</Label>

                                <Input
                                    id="email"
                                    type="email"
                                    className="mt-1 block w-full"
                                    defaultValue={auth.user.email}
                                    name="email"
                                    required
                                    autoComplete="username"
                                    placeholder="Email address"
                                />

                                <InputError
                                    className="mt-2"
                                    message={errors.email}
                                />
                            </div>

                            {mustVerifyEmail &&
                                auth.user.email_verified_at === null && (
                                    <div>
                                        <p className="-mt-4 text-sm text-muted-foreground">
                                            Your email address is unverified.{' '}
                                            <Link
                                                href={send()}
                                                as="button"
                                                className="text-foreground underline decoration-neutral-300 underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current! dark:decoration-neutral-500"
                                            >
                                                Click here to re-send the
                                                verification email.
                                            </Link>
                                        </p>

                                        {status ===
                                            'verification-link-sent' && (
                                            <div className="mt-2 text-sm font-medium text-green-600">
                                                A new verification link has been
                                                sent to your email address.
                                            </div>
                                        )}
                                    </div>
                                )}

                            <div className="flex items-center gap-4">
                                <Button
                                    disabled={processing}
                                    data-test="update-profile-button"
                                >
                                    Save
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>

            <DeleteUser />
        </>
    );
}

Profile.layout = {
    breadcrumbs: [
        {
            title: 'Profile settings',
            href: edit(),
        },
    ],
};
