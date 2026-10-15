import { Form, Head } from '@inertiajs/react';
import { KeyRound, Lock, Mail, Phone, ShieldCheck, User } from 'lucide-react';
import type { ReactNode } from 'react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import ResendInvitationKey from '@/components/resend-invitation-key';
import TeamInvitationAlert from '@/components/team-invitation-alert';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { login } from '@/routes';
import { store } from '@/routes/register';
import type { TeamInvitationContext } from '@/types';

type OrganizationInvitationContext = {
    code: string;
    email: string;
    phone: string | null;
    organizationName: string | null;
    resendUrl: string;
};

type Props = {
    passwordRules: string;
    teamInvitation?: TeamInvitationContext | null;
    organizationInvitation?: OrganizationInvitationContext | null;
};

const inputClass = 'h-11 rounded-xl pl-10';

/** Pole formularza z ikoną po lewej stronie. */
function Field({
    id,
    label,
    icon,
    error,
    children,
}: {
    id: string;
    label: string;
    icon: ReactNode;
    error?: string;
    children: ReactNode;
}) {
    return (
        <div className="grid gap-1.5">
            <Label htmlFor={id} className="text-sm font-medium">
                {label}
            </Label>
            <div className="relative">
                <span className="pointer-events-none absolute inset-y-0 left-3 z-10 flex items-center text-muted-foreground">
                    {icon}
                </span>
                {children}
            </div>
            <InputError message={error} />
        </div>
    );
}

// Strefa czasowa miejsca logowania: zapisujemy ją razem z czasem ostatniego logowania
const timezone = Intl.DateTimeFormat().resolvedOptions().timeZone;

export default function Register({
    passwordRules,
    teamInvitation,
    organizationInvitation,
}: Props) {
    return (
        <>
            <Head title="Rejestracja" />
            <Form
                {...store.form()}
                resetOnSuccess={['password', 'password_confirmation']}
                disableWhileProcessing
                className="flex flex-col gap-5"
            >
                {({ processing, errors }) => (
                    <>
                        <input type="hidden" name="timezone" value={timezone} />
                        {teamInvitation && (
                            <TeamInvitationAlert
                                invitation={teamInvitation}
                                action="Register"
                            />
                        )}

                        {organizationInvitation && (
                            <div className="flex items-start gap-3 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm dark:border-emerald-900 dark:bg-emerald-950/40">
                                <ShieldCheck className="mt-0.5 size-5 shrink-0 text-emerald-600" />
                                <div>
                                    <p className="font-medium">
                                        Zaproszenie do RadegastTradeB2BPlatform
                                    </p>
                                    <p className="text-muted-foreground">
                                        Zostaniesz właścicielem{' '}
                                        {organizationInvitation.organizationName
                                            ? `organizacji ${organizationInvitation.organizationName}`
                                            : 'nowej organizacji'}
                                        . Wpisz klucz z wiadomości e-mail.
                                    </p>
                                </div>
                            </div>
                        )}

                        {teamInvitation && (
                            <input
                                type="hidden"
                                name="team_invitation"
                                value={teamInvitation.code}
                            />
                        )}

                        {organizationInvitation && (
                            <input
                                type="hidden"
                                name="organization_invitation"
                                value={organizationInvitation.code}
                            />
                        )}

                        <Field
                            id="name"
                            label="Imię i nazwisko"
                            icon={<User className="size-4" />}
                            error={errors.name}
                        >
                            <Input
                                id="name"
                                type="text"
                                required
                                autoFocus
                                tabIndex={1}
                                autoComplete="name"
                                name="name"
                                placeholder="Jan Kowalski"
                                className={inputClass}
                            />
                        </Field>

                        <Field
                            id="email"
                            label="Adres e-mail"
                            icon={<Mail className="size-4" />}
                            error={errors.email}
                        >
                            <Input
                                id="email"
                                type="email"
                                required
                                tabIndex={2}
                                autoComplete="email"
                                name="email"
                                defaultValue={
                                    organizationInvitation?.email ??
                                    teamInvitation?.email
                                }
                                readOnly
                                placeholder="jan@firma.pl"
                                className={`${inputClass} bg-muted/50`}
                            />
                        </Field>

                        <Field
                            id="phone"
                            label="Telefon"
                            icon={<Phone className="size-4" />}
                            error={errors.phone}
                        >
                            <Input
                                id="phone"
                                type="tel"
                                required
                                tabIndex={3}
                                autoComplete="tel"
                                name="phone"
                                defaultValue={organizationInvitation?.phone ?? ''}
                                placeholder="600 100 200 lub +48600100200"
                                className={inputClass}
                            />
                        </Field>

                        <div className="grid gap-5 sm:grid-cols-2">
                            <Field
                                id="password"
                                label="Hasło"
                                icon={<Lock className="size-4" />}
                                error={errors.password}
                            >
                                <PasswordInput
                                    id="password"
                                    required
                                    tabIndex={3}
                                    autoComplete="new-password"
                                    name="password"
                                    placeholder="Hasło"
                                    passwordrules={passwordRules}
                                    className={inputClass}
                                />
                            </Field>

                            <Field
                                id="password_confirmation"
                                label="Powtórz hasło"
                                icon={<Lock className="size-4" />}
                                error={errors.password_confirmation}
                            >
                                <PasswordInput
                                    id="password_confirmation"
                                    required
                                    tabIndex={4}
                                    autoComplete="new-password"
                                    name="password_confirmation"
                                    placeholder="Powtórz hasło"
                                    passwordrules={passwordRules}
                                    className={inputClass}
                                />
                            </Field>
                        </div>

                        {organizationInvitation && (
                            <Field
                                id="invitation_key"
                                label="Klucz zaproszenia (6 cyfr z e-maila)"
                                icon={<KeyRound className="size-4" />}
                                error={errors.invitation_key}
                            >
                                <Input
                                    id="invitation_key"
                                    name="invitation_key"
                                    required
                                    inputMode="numeric"
                                    pattern="[0-9]{6}"
                                    maxLength={6}
                                    tabIndex={5}
                                    autoComplete="one-time-code"
                                    placeholder="000000"
                                    className="h-12 rounded-xl pl-10 font-mono text-lg tracking-[0.5em]"
                                />
                            </Field>
                        )}

                        {organizationInvitation && (
                            <ResendInvitationKey
                                url={organizationInvitation.resendUrl}
                            />
                        )}

                        <div className="space-y-3 rounded-2xl border bg-muted/30 p-4">
                            <div className="grid gap-1">
                                <div className="flex items-start gap-3">
                                    <Checkbox
                                        id="terms"
                                        name="terms"
                                        required
                                        tabIndex={7}
                                        className="mt-0.5"
                                    />
                                    <Label
                                        htmlFor="terms"
                                        className="text-sm leading-snug font-normal"
                                    >
                                        Akceptuję{' '}
                                        <a
                                            href="/regulamin"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className="font-medium underline underline-offset-4"
                                        >
                                            regulamin
                                        </a>{' '}
                                        platformy.
                                    </Label>
                                </div>
                                <InputError message={errors.terms} />
                            </div>

                            <div className="grid gap-1">
                                <div className="flex items-start gap-3">
                                    <Checkbox
                                        id="privacy"
                                        name="privacy"
                                        required
                                        tabIndex={8}
                                        className="mt-0.5"
                                    />
                                    <Label
                                        htmlFor="privacy"
                                        className="text-sm leading-snug font-normal"
                                    >
                                        Zapoznałem się z{' '}
                                        <a
                                            href="/rodo"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className="font-medium underline underline-offset-4"
                                        >
                                            informacją o przetwarzaniu danych
                                            osobowych (RODO)
                                        </a>
                                        .
                                    </Label>
                                </div>
                                <InputError message={errors.privacy} />
                            </div>
                        </div>

                        <Button
                            type="submit"
                            className="mt-1 h-11 w-full rounded-xl text-base shadow-md"
                            tabIndex={9}
                            data-test="register-user-button"
                        >
                            {processing && <Spinner />}
                            {organizationInvitation
                                ? 'Utwórz konto właściciela'
                                : 'Utwórz konto'}
                        </Button>

                        <div className="text-center text-sm text-muted-foreground">
                            Masz już konto?{' '}
                            <TextLink
                                href={
                                    teamInvitation
                                        ? login.url({
                                              query: {
                                                  invitation:
                                                      teamInvitation.code,
                                              },
                                          })
                                        : login()
                                }
                                data-test="team-invitation-login-link"
                                tabIndex={10}
                            >
                                Zaloguj się
                            </TextLink>
                        </div>
                    </>
                )}
            </Form>
        </>
    );
}

Register.layout = {
    title: 'Utwórz konto',
    description: 'Wypełnij formularz, aby dołączyć do platformy',
};
