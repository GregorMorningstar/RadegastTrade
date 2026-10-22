import { Link, router, usePage } from '@inertiajs/react';
import { Bell, Mail } from 'lucide-react';
import { Card } from '@/components/adminlte/blocks';

type Invitation = { kind: 'trade' | 'colleague' | 'consent' | 'alert'; title: string; text: string; href: string; expires_at: string | null; dismiss?: string };

/** The invitations (and consents) waiting for an answer from the signed-in person; nothing is rendered when there are none. */
export default function InvitationInbox() {
    const invitations = (usePage().props as { invitations?: Invitation[] }).invitations ?? [];

    if (invitations.length === 0) {
        return null;
    }

    return (
        <div className="mb-4">
            <Card title={`Zaproszenia i powiadomienia (${invitations.length})`} color="warning">
                <ul className="grid gap-2">
                    {invitations.map((invitation, index) => (
                        <li key={`${invitation.href}-${index}`} className="flex items-start gap-3 rounded border border-[#dee2e6] p-3 text-sm">
                            {invitation.kind === 'alert' ? <Bell className="mt-0.5 size-4 shrink-0 text-[#17a2b8]" /> : <Mail className="mt-0.5 size-4 shrink-0 text-[#ffc107]" />}
                            <div className="flex-1">
                                <p className="font-semibold">{invitation.title}</p>
                                <p className="text-[#6c757d]">{invitation.text}</p>
                                {invitation.expires_at && <p className="text-xs text-[#6c757d]">Ważne do {new Date(invitation.expires_at).toLocaleDateString('pl-PL')}</p>}
                            </div>
                            <Link href={invitation.href} className="rounded bg-[#007bff] px-3 py-1.5 text-xs text-white">
                                Zobacz
                            </Link>
                            {invitation.dismiss && (
                                <button type="button" className="rounded border border-[#ced4da] px-3 py-1.5 text-xs" onClick={() => router.post(invitation.dismiss as string, {}, { preserveScroll: true })}>
                                    Zamknij
                                </button>
                            )}
                        </li>
                    ))}
                </ul>
            </Card>
        </div>
    );
}
