import { Head, router, useForm } from '@inertiajs/react';
import { ChevronDown, Send, Trash2 } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import Barcode from 'react-barcode';
import HeadquartersTree from '@/pages/platform/admin/components/HeadquartersTree';
import { Card, Content, ContentHeader } from '@/components/adminlte/blocks';
import UnitStaffPicker from '@/components/unit-staff-picker';
import { Collapse } from '@/pages/platform/admin/components/StructureTiles';
import type { Selected } from '@/pages/platform/admin/components/StructureTiles';

type Invitation = {
    id: number;
    email: string;
    expires_at: string;
    locked: boolean;
};

type Headquarter = {
    id: number;
    name: string;
    barcode: string | null;
    NIP: string | null;
    ownNip: boolean;
    address: string;
    owners: { id: number; name: string; email: string; phone: string | null; role: string }[];
    invitations: Invitation[];
};

type Props = {
    type: 'shops' | 'warehouses';
    newInvitations: Invitation[];
    headquarters: Headquarter[];
};

const texts = {
    shops: {
        title: 'Sklepy',
        singular: 'sklepu',
        empty: 'Nie ma jeszcze żadnej siedziby sklepu. Zaproś kierownika, który ją założy.',
    },
    warehouses: {
        title: 'Hurtownie',
        singular: 'hurtowni',
        empty: 'Nie ma jeszcze żadnej siedziby hurtowni. Zaproś kierownika, który ją założy.',
    },
};

const inputClass = 'w-full rounded border border-[#ced4da] px-3 py-1.5 text-sm';

/**
 * Invitation (email + phone). With `selectableType` (a NEW unit) the owner chooses what to create:
 * a main headquarters (a shop or a warehouse; the invited person becomes its manager) or a branch of one of the
 * headquarters of this page's type (the invited person becomes the branch leader). The invitation goes to the
 * endpoint of the chosen kind.
 */
function InviteForm({
    type,
    headquarter,
    selectableType = false,
    branchTargets = [],
}: {
    type: Props['type'];
    headquarter?: Headquarter;
    selectableType?: boolean;
    branchTargets?: { id: number; name: string }[];
}) {
    const form = useForm({ email: '', phone: '' });
    const [level, setLevel] = useState<'headquarters' | 'branch'>('headquarters');
    const [chosen, setChosen] = useState<Props['type']>(type);
    const [branchHeadquarter, setBranchHeadquarter] = useState('');
    const branch = selectableType && level === 'branch';
    const target = selectableType && !branch ? chosen : type;
    const noun = target === 'shops' ? 'sklepu' : 'hurtowni';

    const submit = (event: FormEvent) => {
        event.preventDefault();

        const url = branch
            ? `/platform/organization/${type}/${branchHeadquarter}/branches/invitations`
            : `/platform/organization/${target}${headquarter ? `/${headquarter.id}` : ''}/invitations`;

        form.post(url, {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    };

    return (
        <form onSubmit={submit} className="flex flex-wrap items-start gap-2">
            {selectableType && (
                <select
                    value={level}
                    onChange={(e) => setLevel(e.target.value as 'headquarters' | 'branch')}
                    aria-label="Jednostka"
                    className="rounded border border-[#ced4da] px-3 py-1.5 text-sm"
                >
                    <option value="headquarters">Główna siedziba</option>
                    <option value="branch">Placówka</option>
                </select>
            )}
            {selectableType && !branch && (
                <select
                    value={chosen}
                    onChange={(e) => setChosen(e.target.value as Props['type'])}
                    aria-label="Rodzaj siedziby"
                    className="rounded border border-[#ced4da] px-3 py-1.5 text-sm"
                >
                    <option value="shops">Sklep</option>
                    <option value="warehouses">Hurtownia</option>
                </select>
            )}
            {branch && (
                <select
                    value={branchHeadquarter}
                    onChange={(e) => setBranchHeadquarter(e.target.value)}
                    required
                    aria-label="Główna siedziba placówki"
                    className="max-w-64 rounded border border-[#ced4da] px-3 py-1.5 text-sm"
                >
                    <option value="">— wybierz główną siedzibę —</option>
                    {branchTargets.map((item) => (
                        <option key={item.id} value={item.id}>
                            {item.name}
                        </option>
                    ))}
                </select>
            )}
            <div>
                <input
                    type="email"
                    value={form.data.email}
                    onChange={(e) => form.setData('email', e.target.value)}
                    placeholder={branch ? 'E-mail kierownika placówki' : 'E-mail kierownika'}
                    required
                    className="w-64 rounded border border-[#ced4da] px-3 py-1.5 text-sm"
                />
                {form.errors.email && (
                    <p className="mt-1 text-xs text-[#dc3545]">{form.errors.email}</p>
                )}
            </div>
            <div>
                <input
                    type="tel"
                    value={form.data.phone}
                    onChange={(e) => form.setData('phone', e.target.value)}
                    placeholder="Telefon (np. +48 600 100 200)"
                    required
                    maxLength={20}
                    className="w-56 rounded border border-[#ced4da] px-3 py-1.5 text-sm"
                />
                {form.errors.phone && (
                    <p className="mt-1 text-xs text-[#dc3545]">{form.errors.phone}</p>
                )}
            </div>
            <button
                type="submit"
                disabled={form.processing}
                className="flex items-center gap-1 rounded bg-[#007bff] px-3 py-1.5 text-sm text-white disabled:opacity-60"
            >
                <Send className="size-4" />
                Wyślij zaproszenie
            </button>
            {selectableType && (
                <p className="basis-full text-xs text-[#6c757d]">
                    Rola zaproszonej osoby: <strong>{branch ? `Kierownik placówki ${noun}` : `Główny kierownik ${noun}`}</strong>.
                    {branch && branchTargets.length === 0 && ' Najpierw zaproś kierownika głównej siedziby: placówka należy do siedziby.'}
                </p>
            )}
        </form>
    );
}

export default function HeadquartersPage({ type, newInvitations, headquarters }: Props) {
    const text = texts[type];
    // the details of a headquarters (data, managers, invitations) are folded until it is clicked
    const [openId, setOpenId] = useState<number | null>(null);

    const openFromTree = (selected: Selected | null) => {
        if (selected?.kind === 'unit' && selected.title === 'Główna siedziba') {
            setOpenId(selected.unit.id);
            // the details open below the list: bring them into view once the fold has opened
            window.setTimeout(() => document.getElementById(`hq-${selected.unit.id}`)?.scrollIntoView({ behavior: 'smooth', block: 'start' }), 350);
        } else if (selected === null) {
            setOpenId(null);
        }
    };

    const cancel = (id: number) => {
        if (confirm('Anulować to zaproszenie?')) {
            router.delete(`/platform/organization/invitations/${id}`, { preserveScroll: true });
        }
    };

    const pendingList = (invitations: Invitation[], className = 'mt-3') =>
        invitations.length > 0 && (
            <ul className={`${className} space-y-1 text-sm`}>
                {invitations.map((invitation) => (
                    <li key={invitation.id} className="flex items-center gap-3">
                        <span>{invitation.email}</span>
                        <span className="text-[#6c757d]">ważne do {invitation.expires_at}</span>
                        {invitation.locked && (
                            <span className="rounded bg-[#dc3545] px-2 py-0.5 text-xs text-white">
                                zablokowane
                            </span>
                        )}
                        <button
                            type="button"
                            onClick={() => cancel(invitation.id)}
                            className="text-[#dc3545] hover:underline"
                            title="Anuluj zaproszenie"
                        >
                            <Trash2 className="size-4" />
                        </button>
                    </li>
                ))}
            </ul>
        );

    return (
        <>
            <Head title={text.title} />
            <ContentHeader
                title={text.title}
                crumbs={[
                    { label: 'Platforma', href: '/platform/organization' },
                    { label: text.title },
                ]}
            />
            <Content>
                <Card title="Zaproś kierownika: głównej siedziby lub placówki" color="success" className="mb-4">
                    <p className="mb-3 text-sm text-[#6c757d]">
                        Wybierz jednostkę (główna siedziba albo placówka), a dla siedziby także rodzaj (sklep lub hurtownia), i podaj e-mail oraz telefon zapraszanej osoby.
                        Zaproszony kierownik założy siedzibę po przyjęciu zaproszenia i poda jej
                        nazwę oraz NIP (taki sam jak NIP organizacji albo inny). Poniżej widać oczekujące
                        zaproszenia dla {text.singular}.
                    </p>
                    <InviteForm type={type} selectableType branchTargets={headquarters.map(({ id, name }) => ({ id, name }))} />
                    {pendingList(newInvitations)}
                </Card>

                {headquarters.length === 0 && (
                    <Card title={text.title} color="primary">
                        {text.empty}
                    </Card>
                )}

                <div className="space-y-4">
                    {headquarters.map((headquarter) => (
                        <div key={headquarter.id} id={`hq-${headquarter.id}`} className="scroll-mt-4">
                            <button
                                type="button"
                                onClick={() => setOpenId(openId === headquarter.id ? null : headquarter.id)}
                                aria-expanded={openId === headquarter.id}
                                className="flex w-full items-center gap-2 rounded border border-[#dee2e6] bg-white px-4 py-3 text-left shadow-sm hover:bg-[#f8f9fa]"
                            >
                                <ChevronDown className={`size-4 shrink-0 text-[#6c757d] transition-transform duration-300 ${openId === headquarter.id ? '' : '-rotate-90'}`} />
                                <span className="font-semibold">{headquarter.name}</span>
                                <span className="text-xs text-[#6c757d]">dane, kierownicy i zaproszenia</span>
                            </button>
                            <Collapse open={openId === headquarter.id}>
                                <div className="pt-2">
                        <Card title={headquarter.name} color="primary">
                            <div className="flex flex-wrap items-start justify-between gap-4">
                                <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
                                    <dt className="text-[#6c757d]">NIP</dt>
                                    <dd>
                                        {headquarter.NIP ?? '—'}
                                        {headquarter.NIP && !headquarter.ownNip && (
                                            <span className="text-[#6c757d]"> (NIP organizacji)</span>
                                        )}
                                    </dd>
                                    <dt className="text-[#6c757d]">Adres</dt>
                                    <dd>{headquarter.address || '—'}</dd>
                                    <dt className="text-[#6c757d]">Kierownicy</dt>
                                    <dd>
                                        {headquarter.owners.length === 0
                                            ? '—'
                                            : headquarter.owners
                                                  .map((o) => `${o.name} (${o.email})`)
                                                  .join(', ')}
                                    </dd>
                                </dl>

                                {headquarter.barcode && (
                                    <div title={`Kod siedziby: ${headquarter.barcode}`}>
                                        <Barcode
                                            value={headquarter.barcode}
                                            format="EAN13"
                                            width={1.4}
                                            height={40}
                                            fontSize={12}
                                            margin={0}
                                            background="transparent"
                                        />
                                    </div>
                                )}
                            </div>

                            <UnitStaffPicker
                                title="Kierownicy siedziby"
                                noun="kierownika siedziby"
                                group={type === 'shops' ? 'sklepu' : 'hurtowni'}
                                staysInUnit={false}
                                unitName={headquarter.name}
                                current={headquarter.owners}
                                candidatesUrl={`/platform/organization/${type}/${headquarter.id}/manager-candidates`}
                                assignUrl={`/platform/organization/${type}/${headquarter.id}/manager`}
                                removeUrl={(userId) => `/platform/organization/${type}/${headquarter.id}/manager/${userId}`}
                                demoteUrl={(userId) => `/platform/organization/${type}/${headquarter.id}/manager/${userId}/role`}
                            />

                            <div className="mt-4 border-t border-[#dee2e6] pt-4">
                                <h4 className="mb-2 text-sm font-semibold">Zaproś kierownika siedziby</h4>
                                <InviteForm type={type} headquarter={headquarter} />

                                {pendingList(headquarter.invitations)}
                            </div>
                        </Card>
                                </div>
                            </Collapse>
                        </div>
                    ))}
                </div>

                <Card title={`Główne siedziby ${text.singular}`} color="primary" className="mt-4">
                    <HeadquartersTree url={`/platform/organization/${type}/tree`} type={type} content="headquarters" onSelect={openFromTree} />
                </Card>
            </Content>
        </>
    );
}
