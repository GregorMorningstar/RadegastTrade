import { router } from '@inertiajs/react';
import { useState } from 'react';
import { btn, btnDanger, btnOutline, Empty, inputCls, td, th } from '@/components/trade/ui';

export type ColleaguesProps = {
    can_invite: boolean;
    people: { id: number; name: string; role: string; type: string | null }[];
    units: { ref: string; name: string; type: string }[];
    sent: { id: number; person: string | null; units: string; status: string; consent: 'none' | 'needed' | 'requested' | 'given'; can_cancel: boolean }[];
    received: { id: number; from: string | null; units: string; consent_required: boolean; consent_requested: boolean; consented: boolean }[];
    consents: { id: number; person: string | null; units: string }[];
    valid_minutes: number;
};

const base = '/trade/colleagues';

const consentLabel = { none: 'bez zgody', needed: 'czeka na prośbę o zgodę', requested: 'czeka na zgodę', given: 'zgoda wyrażona' } as const;
const statusLabel: Record<string, string> = { pending: 'Oczekuje', accepted: 'Przyjęte', rejected: 'Odrzucone', cancelled: 'Cofnięte', expired: 'Wygasło' };

function post(url: string, data: Record<string, unknown> = {}, onSuccess?: () => void) {
    router.post(url, data as never, { preserveScroll: true, onSuccess });
}

/** A step with a code from the e-mail: first the request (the code is sent), then the field and the confirmation. */
function CodeStep({ label, requestUrl, confirmUrl, confirmLabel, minutes }: { label: string; requestUrl: string; confirmUrl: string; confirmLabel: string; minutes: number }) {
    const [sent, setSent] = useState(false);
    const [code, setCode] = useState('');

    return (
        <div className="mt-2 flex flex-wrap items-center gap-2">
            <button type="button" className={btnOutline} onClick={() => post(requestUrl, {}, () => setSent(true))}>
                {sent ? 'Wyślij kod ponownie' : label}
            </button>
            {sent && (
                <>
                    <input
                        className={`${inputCls} !w-32`}
                        value={code}
                        onChange={(e) => setCode(e.target.value.replace(/\D/g, '').slice(0, 6))}
                        inputMode="numeric"
                        autoComplete="one-time-code"
                        placeholder="000000"
                        aria-label={`Kod z e-maila (ważny ${minutes} minut)`}
                    />
                    <button type="button" className={btn} disabled={code.length !== 6} onClick={() => post(confirmUrl, { code }, () => setCode(''))}>
                        {confirmLabel}
                    </button>
                </>
            )}
        </div>
    );
}

export function ColleagueInvitations({ data }: { data: ColleaguesProps }) {
    const [userId, setUserId] = useState('');
    const [units, setUnits] = useState<string[]>([]);
    const person = data.people.find((item) => String(item.id) === userId);
    // only the units of the same kind (shop / warehouse) as the person's own unit
    const usable = data.units.filter((unit) => person?.type === unit.type);

    return (
        <div className="space-y-4">
            {data.received.length > 0 && (
                <div className="space-y-3">
                    {data.received.map((invitation) => (
                        <div key={invitation.id} className="rounded border border-[#dee2e6] p-3 text-sm">
                            <p>
                                <strong>{invitation.from}</strong> zaprasza Cię do jednostek: <strong>{invitation.units}</strong>. Po przyjęciu będziesz mógł(-ła) składać dla nich zamówienia.
                            </p>
                            {invitation.consent_required && !invitation.consented && (
                                <div className="mt-2">
                                    <p className="text-[#6c757d]">Jako lider potrzebujesz zgody właściciela lub (jeśli istnieje) siedziby. Zgodę potwierdzają kodem z e-maila.</p>
                                    <button type="button" className={btnOutline} disabled={invitation.consent_requested} onClick={() => post(`${base}/${invitation.id}/consent-request`)}>
                                        {invitation.consent_requested ? 'Prośba wysłana – czekaj na zgodę' : 'Poproś o zgodę'}
                                    </button>
                                </div>
                            )}
                            {(!invitation.consent_required || invitation.consented) && (
                                <CodeStep label="Wyślij kod na mój e-mail" requestUrl={`${base}/${invitation.id}/accept-code`} confirmUrl={`${base}/${invitation.id}/accept`} confirmLabel="Przyjmij zaproszenie" minutes={data.valid_minutes} />
                            )}
                            <button type="button" className="mt-2 text-xs text-[#dc3545]" onClick={() => post(`${base}/${invitation.id}/reject`)}>
                                odrzuć
                            </button>
                        </div>
                    ))}
                </div>
            )}

            {data.consents.length > 0 && (
                <div className="space-y-3">
                    {data.consents.map((item) => (
                        <div key={item.id} className="rounded border border-[#ffc107] p-3 text-sm">
                            <p>
                                <strong>{item.person}</strong> prosi o zgodę na przyjęcie zaproszenia do: <strong>{item.units}</strong>.
                            </p>
                            <CodeStep label="Wyślij kod na mój e-mail" requestUrl={`${base}/${item.id}/consent-code`} confirmUrl={`${base}/${item.id}/consent`} confirmLabel="Wyrażam zgodę" minutes={data.valid_minutes} />
                        </div>
                    ))}
                </div>
            )}

            {data.can_invite && (
                <form
                    className="space-y-3"
                    onSubmit={(e) => {
                        e.preventDefault();
                        post(base, { user_id: Number(userId), units }, () => {
                            setUserId('');
                            setUnits([]);
                        });
                    }}
                >
                    <label className="block text-sm">
                        Osoba z mojej organizacji
                        <select
                            className={inputCls}
                            value={userId}
                            onChange={(e) => {
                                setUserId(e.target.value);
                                setUnits([]);
                            }}
                            required
                        >
                            <option value="">— wybierz —</option>
                            {data.people.map((item) => (
                                <option key={item.id} value={item.id}>
                                    {item.name} ({item.role})
                                </option>
                            ))}
                        </select>
                    </label>
                    {data.people.length === 0 && <Empty>W Twojej organizacji nie ma jeszcze innych osób, które można zaprosić (dodaj je w zakładce Zespół).</Empty>}
                    <fieldset className="text-sm">
                        <legend className="font-semibold">Jednostki, do których dostaje dostęp</legend>
                        {!person && <Empty>Najpierw wybierz osobę.</Empty>}
                        {person && usable.length === 0 && <Empty>Nie masz jednostek tego rodzaju, które możesz nadać.</Empty>}
                        {usable.map((unit) => (
                            <label key={unit.ref} className="flex items-center gap-2">
                                <input type="checkbox" checked={units.includes(unit.ref)} onChange={(e) => setUnits(e.target.checked ? [...units, unit.ref] : units.filter((ref) => ref !== unit.ref))} />
                                {unit.name}
                            </label>
                        ))}
                    </fieldset>
                    <button type="submit" className={btn} disabled={!userId || units.length === 0}>
                        Zaproś osobę
                    </button>
                    <p className="text-xs text-[#6c757d]">
                        Zaproszenie nic nie przyznaje: osoba przyjmuje je kodem z własnego e-maila, a dostęp dostaje automatycznie po przyjęciu. Gdy zapraszasz lidera, musi on najpierw uzyskać zgodę właściciela
                        lub siedziby (też kodem z e-maila).
                    </p>
                </form>
            )}

            {data.sent.length > 0 && (
                <table className="w-full text-left text-sm">
                    <thead>
                        <tr className="border-b border-[#dee2e6]">
                            <th className={th}>Osoba</th>
                            <th className={th}>Jednostki</th>
                            <th className={th}>Status</th>
                            <th className={th}></th>
                        </tr>
                    </thead>
                    <tbody>
                        {data.sent.map((item) => (
                            <tr key={item.id} className="border-b border-[#dee2e6] last:border-0">
                                <td className={td}>{item.person}</td>
                                <td className={td}>{item.units}</td>
                                <td className={td}>
                                    {statusLabel[item.status] ?? item.status}
                                    {item.status === 'pending' && item.consent !== 'none' && <span className="text-xs text-[#6c757d]"> · {consentLabel[item.consent]}</span>}
                                </td>
                                <td className={td}>
                                    {item.can_cancel && (
                                        <button type="button" className={`${btnDanger} !px-2 !py-0.5 text-xs`} onClick={() => router.delete(`${base}/${item.id}`, { preserveScroll: true })}>
                                            cofnij
                                        </button>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            )}
        </div>
    );
}
