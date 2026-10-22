import { Head, useForm } from '@inertiajs/react';
import { Lock, Plus, Search } from 'lucide-react';
import { useRef, useState } from 'react';
import type { FormEvent } from 'react';
import { clearAutofilled, mergeLookup, newNipAutofill } from '@/lib/nip-autofill';

type FormData = {
    name: string;
    NIP: string;
    email: string;
    phone: string;
    street: string;
    building_number: string;
    apartment_number: string;
    postal_code: string;
    city: string;
    country: string;
};

type Props = {
    /** 'shop' lub 'warehouse': adresy panelu kierownika */
    group: 'shop' | 'warehouse';
    /** Adres e-mail kierownika z zaproszenia (tylko do odczytu) */
    email: string;
    /** Telefon z zaproszenia (można go poprawić) */
    phone?: string | null;
    organizationName: string;
    organizationNip: string | null;
    /** Kierownik placówki (leader) zamiast kierownika siedziby (owner) */
    branch?: boolean;
    /** Nazwa siedziby, do której należy zakładana placówka */
    headquarterName?: string | null;
};

const texts = {
    shop: { title: 'Uzupełnij dane sklepu', singular: 'sklepu' },
    warehouse: { title: 'Uzupełnij dane hurtowni', singular: 'hurtowni' },
};

const inputClass = 'w-full rounded border border-slate-300 bg-transparent px-3 py-1.5 text-sm';
const readOnlyClass = `${inputClass} cursor-not-allowed bg-slate-100 text-slate-600 dark:bg-slate-800`;

const fields: { name: keyof FormData; label: string; maxLength: number }[] = [
    { name: 'phone', label: 'Telefon', maxLength: 20 },
    { name: 'street', label: 'Ulica', maxLength: 255 },
    { name: 'building_number', label: 'Numer budynku', maxLength: 20 },
    { name: 'apartment_number', label: 'Numer lokalu', maxLength: 20 },
    { name: 'postal_code', label: 'Kod pocztowy', maxLength: 10 },
    { name: 'city', label: 'Miejscowość', maxLength: 255 },
    { name: 'country', label: 'Kraj (kod ISO)', maxLength: 2 },
];

/** Formularz siedziby po przyjęciu zaproszenia (organizację wskazuje zaproszenie, nie formularz). */
export default function HeadquarterSetupForm({
    group,
    email,
    phone,
    organizationName,
    organizationNip,
    branch = false,
    headquarterName,
}: Props) {
    const text = branch ? { title: 'Uzupełnij dane placówki', singular: 'placówki' } : texts[group];
    const base = `/${group}/${branch ? 'leader' : 'owner'}`;

    const form = useForm<FormData>({
        name: '',
        NIP: '',
        email,
        phone: phone ?? '',
        street: '',
        building_number: '',
        apartment_number: '',
        postal_code: '',
        city: '',
        country: 'PL',
    });
    const [lookup, setLookup] = useState<{ message: string; error: boolean } | null>(null);
    // which fields the register lookup filled in, and whether the previous NIP was wrong
    const autofill = useRef(newNipAutofill());

    // wrong NIP: the fields filled in from the earlier lookup are emptied
    const rejectNip = (message: string) => {
        form.setData((data) => clearAutofilled(data, autofill.current));
        setLookup({ message, error: true });
    };

    // Dane z rejestru MF po NIP; nazwę uzupełniamy tylko w pustym polu
    const fetchByNip = async () => {
        const nip = form.data.NIP.replace(/\D/g, '');

        if (nip.length !== 10) {
            rejectNip('Podaj 10-cyfrowy NIP.');

            return;
        }

        try {
            const response = await fetch(`${base}/nip-lookup?nip=${encodeURIComponent(nip)}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            const result = await response.json();

            if (!response.ok || !result.ok) {
                rejectNip(result.error ?? 'Nie udało się pobrać danych.');

                return;
            }

            form.setData((data) => ({
                ...mergeLookup(
                    data,
                    {
                        name: result.name,
                        street: result.street,
                        building_number: result.building_number,
                        apartment_number: result.apartment_number,
                        postal_code: result.zip,
                        city: result.city,
                    },
                    autofill.current,
                ),
                NIP: nip,
            }));
            setLookup({ message: 'Pobrano dane z rejestru MF.', error: false });
        } catch {
            setLookup({ message: 'Nie udało się połączyć z serwerem.', error: true });
        }
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(`${base}/setup`);
    };

    return (
        <>
            <Head title={text.title} />

            <div className="mx-auto max-w-3xl space-y-4 py-4">
                <div>
                    <h1 className="text-2xl font-medium">{text.title}</h1>
                    <p className="mt-1 text-sm text-slate-500">
                        {branch ? (
                            <>
                                Zostałeś zaproszony jako kierownik nowej placówki {group === 'shop' ? 'sklepu' : 'hurtowni'}{' '}
                                {headquarterName ? (
                                    <>
                                        w siedzibie <strong>{headquarterName}</strong>
                                    </>
                                ) : (
                                    <>bezpośrednio pod organizacją (bez siedziby)</>
                                )}{' '}
                                (organizacja <strong>{organizationName}</strong>). Podaj nazwę placówki.
                            </>
                        ) : (
                            <>
                                Zostałeś zaproszony jako kierownik nowej siedziby {text.singular} w
                                organizacji <strong>{organizationName}</strong>. Podaj nazwę siedziby.
                            </>
                        )}{' '}
                        NIP może być taki sam jak NIP organizacji albo inny.
                    </p>
                </div>

                <form onSubmit={submit} className="space-y-4 rounded-xl bg-white p-5 shadow-sm dark:bg-slate-900">
                    <div className="grid gap-4 md:grid-cols-2">
                        <div>
                            <label htmlFor="name" className="mb-1 block text-sm font-medium">
                                {branch ? 'Nazwa placówki' : 'Nazwa siedziby'} <span className="text-red-600">*</span>
                            </label>
                            <input
                                id="name"
                                value={form.data.name}
                                required
                                autoFocus
                                maxLength={255}
                                onChange={(e) => form.setData('name', e.target.value)}
                                className={inputClass}
                            />
                            {form.errors.name && <p className="mt-1 text-xs text-red-600">{form.errors.name}</p>}
                        </div>

                        <div>
                            <label htmlFor="email" className="mb-1 flex items-center gap-1 text-sm font-medium">
                                E-mail <Lock className="size-3 text-slate-400" />
                            </label>
                            <input id="email" type="email" value={email} readOnly className={readOnlyClass} />
                        </div>

                        <div className="md:col-span-2">
                            <label htmlFor="NIP" className="mb-1 block text-sm font-medium">
                                NIP
                            </label>
                            <div className="flex gap-2">
                                <input
                                    id="NIP"
                                    value={form.data.NIP}
                                    maxLength={10}
                                    inputMode="numeric"
                                    placeholder="10 cyfr"
                                    onChange={(e) => form.setData('NIP', e.target.value.replace(/\D/g, ''))}
                                    className={inputClass}
                                />
                                <button
                                    type="button"
                                    onClick={fetchByNip}
                                    className="flex shrink-0 items-center gap-1 rounded bg-sky-600 px-3 py-1.5 text-sm text-white hover:bg-sky-700"
                                >
                                    <Search className="size-4" />
                                    Pobierz dane z rejestru
                                </button>
                            </div>
                            {organizationNip && (
                                <button
                                    type="button"
                                    onClick={() => form.setData('NIP', organizationNip)}
                                    className="mt-1 text-xs text-sky-600 hover:underline"
                                >
                                    Użyj NIP {branch ? 'siedziby' : 'organizacji'} ({organizationNip})
                                </button>
                            )}
                            {lookup && (
                                <p
                                    role="status"
                                    className={lookup.error ? 'mt-1 text-xs text-red-600' : 'mt-1 text-xs text-emerald-600'}
                                >
                                    {lookup.message}
                                </p>
                            )}
                            {form.errors.NIP && <p className="mt-1 text-xs text-red-600">{form.errors.NIP}</p>}
                        </div>

                        {fields.map((field) => (
                            <div key={field.name}>
                                <label htmlFor={field.name} className="mb-1 block text-sm font-medium">
                                    {field.label}
                                </label>
                                <input
                                    id={field.name}
                                    value={form.data[field.name]}
                                    maxLength={field.maxLength}
                                    onChange={(e) => form.setData(field.name, e.target.value)}
                                    className={inputClass}
                                />
                                {form.errors[field.name] && (
                                    <p className="mt-1 text-xs text-red-600">{form.errors[field.name]}</p>
                                )}
                            </div>
                        ))}
                    </div>

                    <button
                        type="submit"
                        disabled={form.processing}
                        className="flex items-center gap-1 rounded bg-emerald-600 px-4 py-1.5 text-sm text-white hover:bg-emerald-700 disabled:opacity-60"
                    >
                        <Plus className="size-4" />
                        {branch ? 'Zapisz placówkę' : 'Zapisz siedzibę'}
                    </button>
                </form>
            </div>
        </>
    );
}
