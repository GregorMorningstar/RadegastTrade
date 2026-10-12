import { Head } from '@inertiajs/react';

type Section = { title: string; items: string[] };

// Projekt do uzupełnienia danymi administratora i do weryfikacji przez prawnika/IOD przed publikacją.
const sections: Section[] = [
    {
        title: '1. Administrator danych',
        items: [
            'Administratorem Twoich danych osobowych jest [nazwa i dane operatora, adres, NIP], dalej „Administrator”.',
            'Kontakt w sprawach danych osobowych: [adres e-mail / dane inspektora ochrony danych].',
        ],
    },
    {
        title: '2. Jakie dane przetwarzamy',
        items: [
            'Dane konta: imię i nazwisko, adres e-mail, hasło (przechowywane wyłącznie w postaci zaszyfrowanej), przypisana rola oraz organizacja.',
            'Dane organizacji i placówek podawane w Platformie, np. nazwa, NIP, REGON, adres, telefon.',
            'Dane techniczne: adres IP, informacje o przeglądarce, identyfikator sesji i dzienniki zdarzeń.',
            'Informacja o zaakceptowaniu regulaminu i niniejszej informacji wraz z datą akceptacji.',
        ],
    },
    {
        title: '3. Cele i podstawy prawne przetwarzania (art. 6 RODO)',
        items: [
            'Założenie i prowadzenie konta oraz świadczenie usług w Platformie, czyli wykonanie umowy (art. 6 ust. 1 lit. b RODO).',
            'Zapewnienie bezpieczeństwa Platformy, dochodzenie roszczeń i obrona przed nimi, czyli prawnie uzasadniony interes Administratora (art. 6 ust. 1 lit. f RODO).',
            'Spełnienie obowiązków prawnych, np. podatkowych i rachunkowych (art. 6 ust. 1 lit. c RODO).',
        ],
    },
    {
        title: '4. Odbiorcy danych',
        items: [
            'Dane mogą być przekazywane podmiotom wspierającym działanie Platformy (hosting, wysyłka wiadomości e-mail, wsparcie techniczne) na podstawie umów powierzenia.',
            'Dane są widoczne dla innych użytkowników tej samej organizacji w zakresie wynikającym z ich ról.',
        ],
    },
    {
        title: '5. Okres przechowywania',
        items: [
            'Dane konta przechowujemy przez czas korzystania z Platformy, a po jej zakończeniu przez okres przedawnienia roszczeń lub wymagany przepisami prawa.',
            'Dzienniki techniczne przechowujemy przez [okres].',
        ],
    },
    {
        title: '6. Twoje prawa',
        items: [
            'Masz prawo dostępu do danych, ich sprostowania, usunięcia, ograniczenia przetwarzania, przenoszenia danych oraz wniesienia sprzeciwu wobec przetwarzania opartego na prawnie uzasadnionym interesie.',
            'Masz prawo wniesienia skargi do Prezesa Urzędu Ochrony Danych Osobowych (ul. Stawki 2, 00-193 Warszawa).',
            'Podanie danych jest dobrowolne, ale niezbędne do założenia konta i korzystania z Platformy.',
        ],
    },
    {
        title: '7. Pliki cookies i sesja',
        items: [
            'Platforma używa plików cookies niezbędnych do działania, m.in. do utrzymania sesji po zalogowaniu i ochrony przed atakami CSRF, oraz zapamiętania ustawień wyglądu.',
        ],
    },
];

export default function Privacy() {
    return (
        <>
            <Head title="Polityka prywatności (RODO)" />

            <h1 className="mb-2 text-3xl font-semibold tracking-tight">
                Polityka prywatności (RODO)
            </h1>
            <p className="mb-8 text-sm text-muted-foreground">
                Informacja o przetwarzaniu danych osobowych zgodnie z art. 13
                Rozporządzenia (UE) 2016/679
            </p>

            <div className="space-y-8">
                {sections.map((section) => (
                    <section key={section.title}>
                        <h2 className="mb-3 text-xl font-semibold">{section.title}</h2>
                        <ul className="list-disc space-y-2 pl-5 text-[15px] leading-relaxed">
                            {section.items.map((item) => (
                                <li key={item}>{item}</li>
                            ))}
                        </ul>
                    </section>
                ))}
            </div>
        </>
    );
}
