import { Head } from '@inertiajs/react';

type Section = { title: string; items: string[] };

// Projekt do uzupełnienia danymi operatora i do weryfikacji przez prawnika przed publikacją.
const sections: Section[] = [
    {
        title: '§ 1. Postanowienia ogólne',
        items: [
            'Niniejszy regulamin określa zasady korzystania z platformy RadegastTradeB2BPlatform (dalej: „Platforma”), udostępnianej przez [nazwa i dane operatora, NIP, adres] (dalej: „Operator”).',
            'Platforma służy do obsługi relacji handlowych B2B pomiędzy organizacjami, hurtowniami, sklepami oraz ich pracownikami.',
            'Korzystanie z Platformy oznacza akceptację regulaminu.',
        ],
    },
    {
        title: '§ 2. Konto i rejestracja',
        items: [
            'Rejestracja w Platformie jest możliwa wyłącznie na podstawie zaproszenia wysłanego przez Operatora, administratora lub osobę uprawnioną w ramach organizacji.',
            'Konto zakłada się na adres e-mail, na który wysłano zaproszenie. Zaproszenie może wymagać podania jednorazowego klucza otrzymanego w wiadomości e-mail.',
            'Użytkownik zobowiązuje się do podania prawdziwych danych, ochrony hasła przed osobami trzecimi i niezwłocznego informowania o podejrzeniu nieuprawnionego dostępu.',
            'Zaproszenie ma ograniczony czas ważności. Może zostać wycofane przed jego przyjęciem.',
        ],
    },
    {
        title: '§ 3. Role i uprawnienia',
        items: [
            'Zakres funkcji dostępnych w Platformie zależy od roli przypisanej użytkownikowi (np. właściciel organizacji, kierownik, pracownik).',
            'Użytkownik korzysta wyłącznie z funkcji udostępnionych dla jego roli i nie może próbować uzyskać dostępu do danych innych organizacji.',
        ],
    },
    {
        title: '§ 4. Zasady korzystania',
        items: [
            'Zabronione jest korzystanie z Platformy w sposób sprzeczny z prawem, regulaminem lub zasadami współżycia społecznego, a także zakłócanie jej działania.',
            'Użytkownik odpowiada za treści i dane, które wprowadza do Platformy.',
        ],
    },
    {
        title: '§ 5. Odpowiedzialność',
        items: [
            'Operator dokłada należytej staranności, aby Platforma działała nieprzerwanie, z zastrzeżeniem koniecznych przerw technicznych.',
            'Zakres odpowiedzialności Operatora określają przepisy prawa oraz [dodatkowe postanowienia umowne].',
        ],
    },
    {
        title: '§ 6. Reklamacje',
        items: [
            'Reklamacje dotyczące działania Platformy można zgłaszać na adres [adres e-mail do reklamacji].',
            'Operator rozpatruje reklamację w terminie 14 dni od jej otrzymania.',
        ],
    },
    {
        title: '§ 7. Zmiany regulaminu i postanowienia końcowe',
        items: [
            'Operator może zmienić regulamin z ważnych przyczyn, informując o tym użytkowników z odpowiednim wyprzedzeniem.',
            'W sprawach nieuregulowanych stosuje się przepisy prawa polskiego.',
            'Regulamin obowiązuje od dnia [data].',
        ],
    },
];

export default function Terms() {
    return (
        <>
            <Head title="Regulamin" />

            <h1 className="mb-2 text-3xl font-semibold tracking-tight">Regulamin</h1>
            <p className="mb-8 text-sm text-muted-foreground">
                Regulamin korzystania z platformy RadegastTradeB2BPlatform
            </p>

            <div className="space-y-8">
                {sections.map((section) => (
                    <section key={section.title}>
                        <h2 className="mb-3 text-xl font-semibold">{section.title}</h2>
                        <ol className="list-decimal space-y-2 pl-5 text-[15px] leading-relaxed">
                            {section.items.map((item) => (
                                <li key={item}>{item}</li>
                            ))}
                        </ol>
                    </section>
                ))}
            </div>
        </>
    );
}
