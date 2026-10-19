import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Card, Content, ContentHeader } from '@/components/adminlte/blocks';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { btn, btnDanger, btnOutline, date, Empty, Errors, inputCls, TradeTabs } from '@/components/trade/ui';

type Unit = {
    ref: string;
    name: string;
    kind: string;
    candidates: Person[];
    authorised: { id: number; user: string | null; email: string | null; granted_by: string | null; granted_at: string }[];
};

type Pending = { unit: string; unit_name: string; person: string };

type Person = { id: number; name: string; email: string; role: string };

function GrantForm({ unit, onRequested }: { unit: Unit; onRequested: () => void }) {
    const form = useForm({ unit: unit.ref, user_id: '' });

    return (
        <form
            className="mt-2 flex flex-wrap gap-2"
            onSubmit={(e) => {
                e.preventDefault();
                form.post('/trade/permissions', { preserveScroll: true, onSuccess: () => { form.reset('user_id'); onRequested(); } });
            }}
        >
            <select className={`${inputCls} max-w-xs`} value={form.data.user_id} onChange={(e) => form.setData('user_id', e.target.value)} aria-label={`Pracownik dla ${unit.name}`}>
                <option value="">Wybierz pracownika…</option>
                {unit.candidates.map((person) => (
                    <option key={person.id} value={person.id}>
                        {person.name} ({person.role})
                    </option>
                ))}
            </select>
            <button type="submit" className={btn} disabled={!form.data.user_id || form.processing}>
                Upoważnij do zamawiania (kod z e-maila)
            </button>
        </form>
    );
}

export default function PermissionsIndex({ units, pending }: { units: Unit[]; pending: Pending | null }) {
    const [codeOpen, setCodeOpen] = useState(false);
    const codeForm = useForm({ code: '' });

    return (
        <>
            <Dialog open={pending !== null && codeOpen} onOpenChange={setCodeOpen}>
                <DialogContent className="sm:max-w-sm">
                    <DialogHeader>
                        <DialogTitle>Potwierdź upoważnienie</DialogTitle>
                        <DialogDescription>
                            Wysłaliśmy 6-cyfrowy kod na Twój e-mail. Upoważnienie: {pending?.person} – {pending?.unit_name}. Kod jest ważny 15 minut.
                        </DialogDescription>
                    </DialogHeader>
                    <form
                        className="space-y-3"
                        onSubmit={(e) => {
                            e.preventDefault();
                            codeForm.post('/trade/permissions/confirm', { preserveScroll: true, onSuccess: () => { codeForm.reset(); setCodeOpen(false); } });
                        }}
                    >
                        <input
                            className={inputCls}
                            value={codeForm.data.code}
                            onChange={(e) => codeForm.setData('code', e.target.value.replace(/D/g, '').slice(0, 6))}
                            inputMode="numeric"
                            autoComplete="one-time-code"
                            placeholder="000000"
                            autoFocus
                        />
                        {codeForm.errors.code && <p className="text-sm text-[#dc3545]">{codeForm.errors.code}</p>}
                        <div className="flex justify-end gap-2">
                            <button type="button" className={btnDanger} onClick={() => router.delete('/trade/permissions/pending', { preserveScroll: true, onSuccess: () => { codeForm.reset(); setCodeOpen(false); } })}>
                                Anuluj
                            </button>
                            <button type="submit" className={btn} disabled={codeForm.data.code.length !== 6 || codeForm.processing}>
                                Potwierdź
                            </button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>
            <Head title="Uprawnienia jednostki" />
            <ContentHeader title="Kto może zamawiać dla jednostki" crumbs={[{ label: 'Handel', href: '/trade/available' }, { label: 'Uprawnienia jednostki' }]} />
            <Content>
                <TradeTabs current="permissions" />
                <Errors />
                {pending && (
                    <p className="mb-3 rounded bg-[#fff3cd] px-3 py-2 text-sm text-[#856404]">
                        Upoważnienie dla {pending.person} ({pending.unit_name}) czeka na kod z e-maila.{' '}
                        <button type="button" className="underline" onClick={() => setCodeOpen(true)}>
                            Wpisz kod
                        </button>
                    </p>
                )}
                <p className="mb-3 text-sm text-[#6c757d]">
                    Kierownik jednostki i właściciel organizacji zamawiają zawsze. Pozostali pracownicy tylko wtedy, gdy ich tu upoważnisz, a upoważnić można wyłącznie osobę, która pracuje w tej jednostce.
                    Nadanie i cofnięcie są zapisywane (kto i kiedy).
                </p>
                {units.length === 0 && (
                    <Card>
                        <Empty>Nie zarządzasz żadną jednostką.</Empty>
                    </Card>
                )}
                <div className="space-y-4">
                    {units.map((unit) => (
                        <Card key={unit.ref} title={`${unit.name} (${unit.kind})`} color="primary">
                            {unit.authorised.length === 0 ? (
                                <Empty>Nikt poza kierownictwem nie może zamawiać dla tej jednostki.</Empty>
                            ) : (
                                <ul className="text-sm">
                                    {unit.authorised.map((row) => (
                                        <li key={row.id} className="flex flex-wrap items-center gap-3 border-b border-[#dee2e6] py-2 last:border-0">
                                            <span>
                                                <strong>{row.user}</strong> <span className="text-[#6c757d]">{row.email}</span>
                                            </span>
                                            <span className="text-xs text-[#6c757d]">
                                                nadał(a) {row.granted_by ?? '—'}, {date(row.granted_at)}
                                            </span>
                                            <button className="text-xs text-[#dc3545]" onClick={() => confirm('Cofnąć uprawnienie?') && router.delete(`/trade/permissions/${row.id}`, { preserveScroll: true })}>
                                                cofnij
                                            </button>
                                        </li>
                                    ))}
                                </ul>
                            )}
                            <GrantForm unit={unit} onRequested={() => setCodeOpen(true)} />
                        </Card>
                    ))}
                </div>
            </Content>
        </>
    );
}
