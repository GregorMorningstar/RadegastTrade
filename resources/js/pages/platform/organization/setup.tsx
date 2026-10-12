import { Head, useForm } from '@inertiajs/react';
import { Lock, Search } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';

type Props = {
    /** Adres e-mail właściciela z zaproszenia (tylko do odczytu) */
    email: string;
};

type FormData = {
    name: string;
    NIP: string;
    REGON: string;
    phone: string;
    street: string;
    building_number: string;
    apartment_number: string;
    postal_code: string;
    city: string;
    country: string;
};

type NipResult = {
    ok: boolean;
    error?: string;
    name?: string | null;
    regon?: string | null;
    street?: string | null;
    building_number?: string | null;
    apartment_number?: string | null;
    zip?: string | null;
    city?: string | null;
};

type Field = {
    name: keyof FormData;
    label: string;
    maxLength?: number;
    placeholder?: string;
};

const fields: Field[] = [
    { name: 'REGON', label: 'REGON', maxLength: 9, placeholder: '9 cyfr' },
    { name: 'phone', label: 'Telefon', maxLength: 20 },
    { name: 'street', label: 'Ulica', maxLength: 255 },
    { name: 'building_number', label: 'Numer budynku', maxLength: 20 },
    { name: 'apartment_number', label: 'Numer lokalu', maxLength: 20 },
    { name: 'postal_code', label: 'Kod pocztowy', maxLength: 10 },
    { name: 'city', label: 'Miejscowość', maxLength: 255 },
    { name: 'country', label: 'Kraj (kod ISO)', maxLength: 2, placeholder: 'PL' },
];

const inputClass =
    'w-full rounded border border-slate-300 bg-transparent px-3 py-1.5 text-sm';
const readOnlyClass = `${inputClass} cursor-not-allowed bg-slate-100 text-slate-600 dark:bg-slate-800`;

export default function OrganizationSetup({ email }: Props) {
    const form = useForm<FormData>({
        name: '',
        NIP: '',
        REGON: '',
        phone: '',
        street: '',
        building_number: '',
        apartment_number: '',
        postal_code: '',
        city: '',
        country: 'PL',
    });

    const [lookup, setLookup] = useState<{
        loading: boolean;
        message: string | null;
        error: boolean;
    }>({ loading: false, message: null, error: false });

    // Pobiera dane firmy z rejestru MF po NIP i wstrzykuje je w pola formularza.
    // Nazwę uzupełnia tylko wtedy, gdy pole jest puste; e-mail zostaje bez zmian.
    const fetchByNip = async () => {
        const nip = form.data.NIP.replace(/\D/g, '');

        if (nip.length !== 10) {
            setLookup({ loading: false, message: 'Podaj 10-cyfrowy NIP.', error: true });

            return;
        }

        setLookup({ loading: true, message: null, error: false });

        try {
            const response = await fetch(
                `/platform/organization/nip-lookup?nip=${encodeURIComponent(nip)}`,
                {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                },
            );
            const result: NipResult = await response.json();

            if (!response.ok || !result.ok) {
                setLookup({
                    loading: false,
                    message: result.error ?? 'Nie udało się pobrać danych.',
                    error: true,
                });

                return;
            }

            form.setData((data) => ({
                ...data,
                NIP: nip,
                REGON: result.regon ?? data.REGON,
                street: result.street ?? data.street,
                building_number: result.building_number ?? data.building_number,
                apartment_number: result.apartment_number ?? data.apartment_number,
                postal_code: result.zip ?? data.postal_code,
                city: result.city ?? data.city,
                // nazwa z rejestru tylko jako podpowiedź w pustym polu
                name: data.name || (result.name ?? ''),
            }));

            setLookup({
                loading: false,
                message: result.name
                    ? `Pobrano dane z rejestru MF: ${result.name}`
                    : 'Pobrano dane z rejestru MF.',
                error: false,
            });
        } catch {
            setLookup({
                loading: false,
                message: 'Nie udało się połączyć z serwerem.',
                error: true,
            });
        }
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post('/platform/organization/setup');
    };

    return (
        <>
            <Head title="Dane organizacji" />

            <div className="mx-auto max-w-3xl space-y-4 py-4">
                <div>
                    <h1 className="text-2xl font-medium">Uzupełnij dane organizacji</h1>
                    <p className="mt-1 text-sm text-slate-500">
                        Zostałeś zaproszony jako właściciel nowej organizacji.
                        Podaj nazwę organizacji. E-mail pochodzi z zaproszenia i
                        nie można go zmienić. Dane możesz pobrać z rejestru po NIP.
                    </p>
                </div>

                <form
                    onSubmit={submit}
                    className="space-y-4 rounded-xl bg-white p-5 shadow-sm dark:bg-slate-900"
                >
                    <div className="grid gap-4 md:grid-cols-2">
                        <div>
                            <label htmlFor="name" className="mb-1 block text-sm font-medium">
                                Nazwa organizacji <span className="text-red-600">*</span>
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
                            {form.errors.name && (
                                <p className="mt-1 text-xs text-red-600">{form.errors.name}</p>
                            )}
                        </div>

                        <div>
                            <label htmlFor="email" className="mb-1 flex items-center gap-1 text-sm font-medium">
                                E-mail <Lock className="size-3 text-slate-400" />
                            </label>
                            <input
                                id="email"
                                type="email"
                                value={email}
                                readOnly
                                className={readOnlyClass}
                            />
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
                                    onChange={(e) =>
                                        form.setData('NIP', e.target.value.replace(/\D/g, ''))
                                    }
                                    className={inputClass}
                                />
                                <button
                                    type="button"
                                    onClick={fetchByNip}
                                    disabled={lookup.loading}
                                    className="flex shrink-0 items-center gap-1 rounded bg-sky-600 px-3 py-1.5 text-sm text-white hover:bg-sky-700 disabled:opacity-60"
                                >
                                    <Search className="size-4" />
                                    {lookup.loading ? 'Szukam…' : 'Pobierz dane z rejestru'}
                                </button>
                            </div>
                            {lookup.message && (
                                <p
                                    role="status"
                                    className={
                                        lookup.error
                                            ? 'mt-1 text-xs text-red-600'
                                            : 'mt-1 text-xs text-emerald-600'
                                    }
                                >
                                    {lookup.message}
                                </p>
                            )}
                            {form.errors.NIP && (
                                <p className="mt-1 text-xs text-red-600">{form.errors.NIP}</p>
                            )}
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
                                    placeholder={field.placeholder}
                                    onChange={(e) => form.setData(field.name, e.target.value)}
                                    className={inputClass}
                                />
                                {form.errors[field.name] && (
                                    <p className="mt-1 text-xs text-red-600">
                                        {form.errors[field.name]}
                                    </p>
                                )}
                            </div>
                        ))}
                    </div>

                    <button
                        type="submit"
                        disabled={form.processing}
                        className="rounded bg-emerald-600 px-4 py-1.5 text-sm text-white hover:bg-emerald-700 disabled:opacity-60"
                    >
                        Zapisz organizację
                    </button>
                </form>
            </div>
        </>
    );
}
