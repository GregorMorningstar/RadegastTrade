# Role w RadegastTrade

Dokumentacja ról platformy: kto kogo zaprasza, co widzi i co może. Role są zdefiniowane w `app/Enums/UserRole.php`.

## Hierarchia

```
Administrator platformy
 └─ zaprasza → Właściciel organizacji            (ORGANIZATION_OWNER)
      └─ zaprasza → Główny kierownik siedziby    (SHOP_OWNER / WAREHOUSE_OWNER)
           └─ zaprasza → Kierownik placówki      (SHOP_LEADER / WAREHOUSE_LEADER)
                └─ zaprasza → Pracownik          (SHOP_WORKER / WAREHOUSE_WORKER)
```

Struktura firmy: **organizacja → główne siedziby (sklepy i hurtownie) → placówki → ludzie**. Każde zaproszenie niesie klucze obce
(organizacja → siedziba → placówka), pobierane z konta zapraszającego, nigdy z formularza.

Poza łańcuchem są jeszcze dwie role bez panelu: **Manager** i **Pracownik platformy** (`Employee`).

## Skrót: role i uprawnienia

| Rola (`value`) | Etykieta | Zaprasza | Panel (adres) |
|---|---|---|---|
| `admin` | Administrator | właścicieli organizacji | `/platform/admin` |
| `organization_owner` | Właściciel organizacji | głównych kierowników siedzib i kierowników placówek | `/platform/organization` |
| `shop_owner` | Główny kierownik (sklep) | kierowników placówek sklepu | `/shop/owner` |
| `warehouse_owner` | Główny kierownik (hurtownia) | kierowników placówek hurtowni | `/warehouse/owner` |
| `shop_leader` | Leader (sklep) | pracowników placówki sklepu | `/shop/leader` |
| `warehouse_leader` | Leader (hurtownia) | pracowników placówki hurtowni | `/warehouse/leader` |
| `shop_worker` | Pracownik (sklep) | nikogo | `/shop/worker` |
| `warehouse_worker` | Pracownik (hurtownia) | nikogo | `/warehouse/worker` |
| `manager` | Manager | nikogo | brak panelu |
| `employee` | Pracownik (platformy) | nikogo | brak panelu |

Łańcuch zaproszeń wynika z tras i serwisów zaproszeń (opisany też w komentarzach `UserRole`). Konto bez panelu (`UserRole::hasPanel()` = false)
trafia po zalogowaniu na stronę `/account/pending` („Twoje konto nie ma jeszcze panelu”).

---

## Administrator (`admin`)

Administrator platformy. Zaprasza organizacje (ich właścicieli) i ma podgląd całej struktury.

- **Zaproszenia:** wysyła zaproszenie właścicielowi **nowej organizacji** (e-mail; dane organizacji uzupełnia zaproszony po przyjęciu).
  Zaproszenie zawsze zakłada nową organizację. Zaproszenie można anulować.
- **Menu:** Dashboard, Panel, Organizacje, Hurtownie (Główne siedziby, Placówki), Sklepy (Główne siedziby, Placówki), Czat, Profil.
- **Organizacje** (`/platform/admin/organizations`): lista z formularzem „Zaproś właściciela nowej organizacji”; przycisk „Osoby” rozwija
  strukturę organizacji (siedziby, placówki, osoby) z czatem.
- **Hurtownie / Sklepy** (`/platform/admin/{warehouses|shops}/my-headquarters`): kafelki wszystkich głównych siedzib ze wszystkich
  organizacji z drzewem placówek i osób, wyszukiwarką i stronicowaniem (po 5). Starsze listy w tabelach: `/headquarters`, `/branches`.
- **Czat:** może pisać z osobami z dowolnej organizacji, a one widzą administratora na liście kontaktów.
- **Nie może:** przełączać kont osób (to robi właściciel organizacji i lider placówki) ani zmieniać składu jednostek.

## Właściciel organizacji (`organization_owner`)

Właściciel jednej organizacji (jedna organizacja na konto). Po przyjęciu zaproszenia najpierw uzupełnia dane organizacji
(nazwa, NIP, adres; NIP jest weryfikowany sumą kontrolną i w rejestrze MF).

- **Zaproszenia** (`/platform/organization/{shops|warehouses}` i `/branches`):
  - **główna siedziba** – rodzaj (sklep lub hurtownia), e-mail, telefon; zaproszony dostaje rolę głównego kierownika, zakłada siedzibę
    (nazwa, NIP – taki sam jak organizacji albo inny, nieużywany);
  - **placówka** – wybiera główną siedzibę, e-mail, telefon; zaproszony dostaje rolę kierownika placówki, zakłada placówkę.
  - Zaproszenia można anulować; nowe zaproszenie na ten sam adres wycofuje poprzednie.
- **Menu:** Dashboard, Panel, Sklepy (Główne sklepy, Placówki sklepów), Hurtownie (Główne hurtownie, Placówki hurtowni), Zespół, Czat, Profil.
- **Główne sklepy / hurtownie:** zwinięte szczegóły każdej siedziby (dane, kierownicy, zaproszenie) i lista siedzib z panelem szczegółów po prawej.
- **Placówki:** formularz zaproszenia kierownika placówki i kafelki siedzib z ich placówkami i osobami.
- **Kierownicy siedziby:** może dodać kilku kierowników (praca zmianowa) z listy osób organizacji, odwołać kierownika albo zmienić jego rolę
  na pracownika **tej samej grupy** (sklep/hurtownia). Przed zmianą pojawia się ostrzeżenie o skutkach.
- **Zespół:** wszyscy w organizacji (organizator, kierownicy siedzib, liderzy, pracownicy) z rolami, statusem i przyciskiem
  **Dezaktywuj / Aktywuj** konta.
- **Czat:** z osobami z własnej organizacji.
- **Granice:** widzi i zmienia wyłącznie własną organizację (id bierze serwer z jego konta). Nie dezaktywuje siebie ani innych właścicieli.

## Główny kierownik siedziby (`shop_owner`, `warehouse_owner`)

Zarządza jedną główną siedzibą (sklepu albo hurtowni). Po przyjęciu zaproszenia zakłada siedzibę. Siedziba może mieć kilku kierowników.

- **Zaproszenia:** kierownika **placówki swojej siedziby** (e-mail, telefon). Przed zaproszeniem widać, czy to sklep, czy hurtownia – rodzaj wynika
  z jego roli.
- **Menu:** Dashboard, Panel, Placówki, Siedziba i placówki, Zespół, Czat, Profil.
- **Placówki** (`/{shop|warehouse}/owner/branches`): zaproszenia, lista placówek z kierownikami. Przy placówce może dodać kierownika z listy osób
  organizacji, odwołać go albo zmienić na pracownika tej placówki.
- **Siedziba i placówki** (`/{shop|warehouse}/owner/structure`): podgląd własnej siedziby (kontakt, kod kreskowy, osoby) i jej placówek.
- **Zespół:** organizator, kierownicy tej siedziby i kierownicy jej placówek.
- **Granice:** tylko własna siedziba i jej placówki. Nie przełącza aktywności kont.

## Kierownik placówki (`shop_leader`, `warehouse_leader`)

Kierownik jednej placówki. Po przyjęciu zaproszenia zakłada placówkę (nazwa, NIP).

- **Zaproszenia:** pracowników swojej placówki (e-mail, telefon); można je anulować.
- **Menu:** Dashboard, Panel, Pracownicy, Zespół, Czat, Profil.
- **Pracownicy** (`/{shop|warehouse}/leader/workers`): lista pracowników placówki i zaproszenia.
- **Zespół:** kierownik i pracownicy placówki; przy pracownikach przycisk **Dezaktywuj / Aktywuj**.
- **Granice:** placówka musi mieć **co najmniej jednego kierownika** – jedynego nie można odwołać, zmienić ani przenieść. Dezaktywować może
  tylko pracowników własnej placówki (nie siebie ani innego lidera).

## Pracownik placówki (`shop_worker`, `warehouse_worker`)

Pracuje w jednej placówce. Rolę dostaje z zaproszenia od kierownika placówki.

- **Menu:** Dashboard, Panel, Zespół (kierownik i współpracownicy, tylko do odczytu), Czat, Profil.
- **Nie zaprasza** i nie zmienia składu. Jego konto może wyłączyć kierownik placówki albo właściciel organizacji.

## Manager (`manager`) i Pracownik platformy (`employee`)

Konta bez panelu. Po zalogowaniu lądują na stronie „konto nie ma jeszcze panelu”.

- **Podgląd struktury (tylko odczyt):** administrator, manager i pracownik platformy mają dostęp do endpointów podglądu:
  `/platform/headquarters/{shops|warehouses}/tree` i `/platform/organizations/{id}/members` (drzewo siedzib, placówek i osób, wyszukiwanie).
  Same strony podglądu są w panelu administratora.
- **Czat:** Manager (jak administrator) może pisać z osobami z organizacji; Pracownik platformy nie.
- `employee` jest też domyślną rolą nowego konta, zanim zaproszenie nada inną (np. przy rejestracji z zaproszenia do zespołu).

---

## Pozycja w jednostce (kierownik / pracownik)

Oprócz roli konta jest tabela `unit_memberships`: kto jest **kierownikiem**, a kto **pracownikiem** danej jednostki
(główna siedziba lub placówka, sklep lub hurtownia). Jedna osoba jest w najwyżej jednej jednostce.

| Rola konta | Jednostka | Pozycja |
|---|---|---|
| `*_owner` | główna siedziba | kierownik |
| `*_leader` | placówka | kierownik |
| `*_worker` | placówka | pracownik |

Tabela jest utrzymywana automatycznie przy każdej zmianie roli lub jednostki konta (`UserObserver`); repozytorium (`UnitMembershipRepositoryInterface::place`)
ustawia osobę w jednostce razem z jej rolą. Główna siedziba ma tylko kierowników; pracownicy są w placówkach.

## Aktywność konta

`users.is_active` – konto włącza i wyłącza **właściciel organizacji** (dowolna osoba ze struktury) i **kierownik placówki** (pracowników swojej placówki).
Zapisywane jest kto i kiedy (`deactivated_by`, `deactivated_at`). Wyłączona osoba **loguje się**, ale każda strona kieruje na
`/account/inactive` („konto zostało wyłączone przez … dnia …”). Zostaje tylko wylogowanie i **czat z osobą, która wyłączyła konto, i z administratorami**.

## Czat

Czat 1:1 w stylu Messengera (Echo + Reverb, z odpytywaniem jako zapasem): tylko osoby z jednej organizacji. Wyjątek: administrator i manager
rozmawiają z osobami z dowolnej organizacji. Konto bez organizacji (poza administratorem i managerem) nie ma z kim pisać.

## Konta testowe (seedery)

Hasło wszystkich: `qwer1234`. `DakarSeeder` (`php artisan db:seed --class=DakarSeeder`) tworzy organizację DAKAR (NIP 6851047393):

| Rola | E-mail |
|---|---|
| Administrator | `dakar_admin@test.pl` |
| Manager | `dakar_manager@test.pl` |
| Pracownik platformy | `dakar_employee@test.pl` |
| Właściciel organizacji | `dakar_organization@test.pl` |
| Kierownik siedziby hurtowni / sklepu | `dakar_warehouse_owner@test.pl` / `dakar_shop_owner@test.pl` |
| Kierownik placówki hurtowni / sklepu | `dakar_warehouse_leader@test.pl` / `dakar_shop_leader@test.pl` |
| Pracownicy hurtowni (3) | `dakar_warehouse_worker_1…3@test.pl` |
| Pracownicy sklepu (3) | `dakar_shop_worker_1…3@test.pl` |

## Gdzie to jest w kodzie

| Temat | Plik |
|---|---|
| Role, panele, łańcuch zaproszeń | `app/Enums/UserRole.php` |
| Trasy paneli i ograniczenia po rolach | `routes/web.php`, middleware `role:` (`EnsureUserHasRole`) |
| Zaproszenia: siedziby, placówki, pracownicy | `HeadquarterInvitationService`, `BranchService`, `BranchWorkerService` |
| Kierownicy siedzib i placówek | `HeadquarterManagerService`, `BranchLeaderController` |
| Pozycje w jednostkach, aktywność kont | `UnitMembershipService` (włączanie i wyłączanie kont), `UnitMembershipRepositoryInterface`, `UserObserver` |
| Podgląd struktury (kafelki, szczegóły) | `OrganizationMembersService`, `resources/js/pages/platform/admin/components/StructureTiles.tsx` |
| Czat | `ChatService`, `ChatController` |
| Konto nieaktywne / bez panelu | `EnsureUserIsActive`, `AccountInactiveController`, `AccountPendingController` |
