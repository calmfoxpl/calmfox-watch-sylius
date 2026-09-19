# Calmfox Watch dla Sylius

[English](README.md) · **Polski**

Pakiet monitoringu wnętrza sklepu Sylius. Wystawia jeden sekretny adres
kontrolny, który odpytuje monitoring Calmfox Watch, i realizuje ten sam
kontrakt, co wtyczka WordPressa oraz pakiety dla Magento i Neosa: ten sam
kształt odpowiedzi, ten sam podpis, ta sama droga parowania.

Model jest „pull": pakiet nie wysyła nic z siebie poza rejestracją, parowaniem
i rozłączeniem. Reszta to odpowiedzi na pytania monitoringu.

## Wymagania

| Składnik | Zakres |
| --- | --- |
| PHP | 8.1 i wyżej |
| Symfony | 6.4 albo 7.x |
| Sylius | 1.12 i wyżej, w tym 2.x |

Zakres Syliusa zapisany jest jako `"sylius/sylius": "^1.12 || ^2.0"`, czyli
tak, jak robią to pozostałe pakiety w tym ekosystemie: `sylius/sylius` jest
metapakietem całej platformy i to on rozstrzyga, co jest dostępne. Dwie uwagi:

- Sylius 2.x wymaga PHP 8.2, więc na PHP 8.1 Composer rozwiąże zależność
  do gałęzi 1.13. To nie jest błąd konfiguracji, tylko konsekwencja obu
  zakresów naraz.
- Miejsca, w których 1.x i 2.x różnią się API (menu panelu, nazwa layoutu
  administracyjnego), są w pakiecie napisane obronnie: przy nieznanej
  strukturze pakiet nie dokłada pozycji w menu, zamiast wywalać panel.
  Adres ekranu i polecenia CLI działają wtedy tak samo.

## Instalacja

Pakiet jest w publicznym katalogu pakietów Composera (Packagist) jako
`calmfox/watch-sylius`.

### 1. Instalacja pakietu

```bash
composer require calmfox/watch-sylius
```

Aktualizacja: `composer update calmfox/watch-sylius`.

Tam, gdzie sklep nie może korzystać z Packagista, pakiet instaluje się z paczki
`calmfox-watch-sylius.zip`, którą podaje panel Calmfox Watch (Integracje, przycisk
„Pobierz dla Sylius"). W paczce jest jeden katalog: `calmfox-watch/`. Także wtedy
najlepiej iść przez Composera: przelicza autoloader i pilnuje zależności pakietu
tak samo, jak przy pakiecie pobranym z Packagista.

```bash
mkdir -p pakiety && unzip calmfox-watch-sylius.zip -d pakiety
composer config repositories.calmfox-watch '{"type":"path","url":"./pakiety/calmfox-watch","options":{"symlink":false}}'
composer require calmfox/watch-sylius:@dev
```

Trzy miejsca, w których łatwo się potknąć:

- **`"symlink": false`** każe Composerowi skopiować pliki. Bez tego katalog
  w `vendor` jest wyłącznie dowiązaniem do `pakiety` i zniknie razem z nim.
- **Rozpakowany katalog zostaje w projekcie** (i w repozytorium, jeżeli wdrożenie
  idzie z gita). Composer czyta go przy każdym `composer install`, więc jego
  skasowanie wywróci następne wdrożenie.
- **`@dev` przy nazwie pakietu jest konieczne.** `composer.json` paczki świadomie
  nie ma pola `version` (Composer wylicza wersję z tagu repozytorium, a paczka
  tagu nie ma), więc repozytorium typu `path` melduje ją jako `dev-main`.

Aktualizacja z paczki: rozpakowanie nowszej w to samo miejsce i
`composer update calmfox/watch-sylius`.

Wdrożenia, w których na serwerze nie ma Composera, mogą rozpakować paczkę do
katalogu projektu i dopisać przestrzeń nazw do `composer.json` aplikacji:

```json
"autoload": {
    "psr-4": {
        "Calmfox\\WatchBundle\\": "pakiety/calmfox-watch/src/"
    }
}
```

Autoloader trzeba wtedy przeliczyć tam, gdzie Composer jest (`composer dump-autoload`),
i wysłać razem z `vendor`. Trasy, szablony i tłumaczenia pakietu leżą w `src/Resources`,
czyli w katalogu klasy pakietu, więc odwołania `@CalmfoxWatchBundle/...` z kolejnych
kroków działają przy obu drogach tak samo.

### 2. Rejestracja pakietu

`config/bundles.php` (Symfony Flex tego nie dopisze: wpis powstaje z przepisu
Flex, a przepisy są publikowane tylko dla pakietów z publicznego katalogu):

```php
return [
    // ...
    Calmfox\WatchBundle\CalmfoxWatchBundle::class => ['all' => true],
];
```

### 3. Trasy

`config/routes/calmfox_watch.yaml`:

```yaml
calmfox_watch:
    resource: '@CalmfoxWatchBundle/Resources/config/routes.yaml'
```

Powstają dwie rzeczy: publiczny adres kontrolny `/calmfox-watch/health`
oraz ekran w panelu pod `/admin/calmfox-watch`.

### 4. Dostęp do adresu kontrolnego

Adres kontrolny MUSI być publiczny. To monitoring nas odpytuje, a nie
odwrotnie, i nie ma sesji, którą mógłby się wykazać. Autoryzacją jest sekret
w parametrze `key`, porównywany funkcją `hash_equals`.

W `config/packages/security.yaml`, w `access_control`, **przed** regułami
Syliusa:

```yaml
security:
    access_control:
        - { path: "^/calmfox-watch/health", roles: PUBLIC_ACCESS }
        # ... dotychczasowe reguły Syliusa ...
```

Na Syliusie 1.12 z Symfony 5.4 rola nazywa się `IS_AUTHENTICATED_ANONYMOUSLY`.

Sprawdź też, czy zapora sieciowa albo reguły serwera WWW nie blokują tej
ścieżki. Ekran w panelu i polecenie `calmfox:watch:status` wykonują
samokontrolę pętlą zwrotną i powiedzą wprost, jeżeli adres jest niedostępny
z samego serwera.

### 5. Połączenie z panelem

Najkrócej: panel administracyjny sklepu, pozycja **Calmfox Watch** w menu głównym,
przycisk „Połącz przez watch.calmfox.net". Przeniesie Cię do panelu (logowanie
albo założenie konta, wybór organizacji), a potem wróci do sklepu z kluczem
instalacyjnym i połączy sklep bez przepisywania czegokolwiek. Sklep nie musi
wcześniej istnieć w panelu.

Powrót jest chroniony znacznikiem jednorazowym (kwadrans), a panel wpuszcza
wyłącznie adres powrotny na domenie łączonego sklepu, prowadzący do ekranu
pakietu. Sklep z panelem administracyjnym na osobnej domenie tej drogi nie
dostanie: przycisk się wtedy nie pokaże, a zostaje połączenie kluczem.

Dwie pozostałe drogi (obie z tego samego ekranu albo z wiersza poleceń):

```bash
# nowe konto w pakiecie Free
bin/console calmfox:watch:register wlasciciel@sklep.pl

# albo dopięcie do istniejącej strony w panelu (klucz z ekranu Integracje)
bin/console calmfox:watch:pair fxp_live_0123456789abcdef
```

W CLI router nie zna adresu sklepu, więc albo ustaw `framework.router.default_uri`,
albo podaj `calmfox_watch.site_url`. Oba polecenia wypisują adres kontrolny,
który zgłaszają do panelu, więc od razu widać, czy jest poprawny.

## Konfiguracja

Wszystko ma sensowne wartości domyślne. `config/packages/calmfox_watch.yaml`
potrzebny jest tylko wtedy, gdy coś odbiega od standardu:

```yaml
calmfox_watch:
    # Adres API. Zmienna środowiskowa: CALMFOX_WATCH_API_URL.
    api_url: 'https://watch.calmfox.net'

    # Katalog stanu. Zmienna środowiskowa: CALMFOX_WATCH_STATE_DIR.
    state_dir: '%kernel.project_dir%/var/calmfox-watch'

    # Adres sklepu, używany w CLI i przez sprawdzenie HTTPS.
    site_url: 'https://sklep.pl'

    # Prefiks panelu, jeżeli zmieniałeś sylius_admin_path.
    admin_path: 'admin'

    # Kafelek z kondycją sklepu na pulpicie panelu. false zostawia pulpit nietknięty.
    dashboard_widget: true

    # Layout panelu. Nazwa różni się między Syliusem 1.x a 2.x.
    admin_layout: '@SyliusAdmin/layout.html.twig'

    # Limit dysku konta hostingowego w GB. To samo ustawisz z ekranu w panelu
    # i wtedy wartość z ekranu ma pierwszeństwo.
    disk_quota_gb: 20

    media_dir: '%kernel.project_dir%/public/media'
    mailer_dsn: '%env(MAILER_DSN)%'
    elasticsearch_url: null          # puste = sklep go nie używa, check nie powstaje
    messenger_table: 'messenger_messages'
    failure_transports: ['failed']
    heartbeat_interval: 600          # co ile sekund cron woła calmfox:watch:heartbeat
    platform_package: 'sylius/sylius'
    composer_binary: 'composer'
```

`api_url` i `state_dir` czytane są ze zmiennych środowiskowych już przy
budowaniu kontenera, więc po ich zmianie wyczyść pamięć podręczną
(`bin/console cache:clear`).

### Layout, menu i kafelek na pulpicie

Sylius 1.x i 2.x mają inny szablon bazowy panelu. Sprawdź, jak nazywa się
w Twojej wersji (`bin/console debug:twig`, albo katalog
`vendor/sylius/sylius/src/Sylius/Bundle/AdminBundle/Resources/views`),
i ustaw `calmfox_watch.admin_layout`.

**Menu główne.** Pozycja „Calmfox Watch" dopina się przez zdarzenie
`sylius.menu.admin.main` do MENU GŁÓWNEGO, zaraz pod pulpitem, a nie pod
„Konfigurację": awaria ma być widoczna z każdego ekranu sklepu. Jeżeli Twoja
wersja buduje menu inaczej, pozycja się nie pojawi, a ekran nadal działa pod
adresem `/admin/calmfox-watch`.

**Kafelek na pulpicie.** Pulpit panelu dostaje skrót kondycji: status sekcji,
liczby sprawdzeń i najwyżej trzy najpilniejsze problemy (awarie przed
ostrzeżeniami), z odnośnikiem do pełnego ekranu. Wpinamy go sami, bez
grzebania w konfiguracji sklepu: w Syliusie 1.x przez zdarzenie szablonu
`sylius.admin.dashboard.content`, w 2.x przez hook
`sylius_admin.dashboard.index.content`. Kafelek renderuje się podzapytaniem do
akcji pakietu, z tym samym uprawnieniem i tą samą pamięcią podręczną co ekran,
a błąd w nim nie ma prawa zatrzymać pulpitu. Wyłącza się jednym ustawieniem:
`calmfox_watch.dashboard_widget: false`.

## Stan, sekret i wdrożenia z katalogiem na wydanie

Stan (sekret adresu kontrolnego, znacznik parowania, historia wersji, znacznik
bicia serca, policzone aktualizacje) leży w **pliku**, nie w bazie:
`var/calmfox-watch/state.json`, prawa 600, zapis atomowy.

Powód jest w kontrakcie: przy padniętej bazie adres kontrolny ma odpowiedzieć
`db: fail` i kodem 503, a nie zamilknąć. To jedyny moment, w którym monitoring
naprawdę zarabia.

> **Uwaga przy wdrożeniach typu „nowy katalog na każde wydanie"** (Deployer,
> Capistrano, symlink `current`): katalog stanu MUSI być współdzielony między
> wydaniami. Inaczej każde wdrożenie tworzy nowy sekret, adres kontrolny
> zapamiętany w panelu przestaje działać i monitoring zgłasza milczący sklep.
> Historia zmian wersji też zaczyna się wtedy od zera.
>
> Dodaj `var/calmfox-watch` do katalogów współdzielonych, albo ustaw
> `CALMFOX_WATCH_STATE_DIR=/var/www/sklep/shared/calmfox-watch`.

Wymiana sekretu (przycisk „Wymień klucz") działa z oknem 15 minut: nowy sekret
obowiązuje od razu, poprzedni jest honorowany jeszcze kwadrans, więc nieudane
przepięcie w panelu nie zrywa monitoringu.

## Zadania cykliczne

Sklep Sylius ma polecenia, które MUSZĄ chodzić regularnie, i pakiet nie zastąpi
ich uruchamiania. Pilnuje natomiast, czy cron w ogóle żyje:

```cron
# stan crona dla Calmfox Watch (tanie, samo zapisuje znacznik czasu)
*/10 * * * * cd /var/www/sklep && php bin/console calmfox:watch:heartbeat -q

# zaległe aktualizacje pakietów (raz na dobę wystarczy, potrzebuje sieci)
15 3 * * *   cd /var/www/sklep && php bin/console calmfox:watch:updates -q

# właściwe zadania sklepu
*/5 * * * *  cd /var/www/sklep && php bin/console sylius:remove-expired-carts -q
*/5 * * * *  cd /var/www/sklep && php bin/console sylius:cancel-unpaid-orders -q
0 4 * * *    cd /var/www/sklep && php bin/console sylius:remove-expired-payments -q
```

Nazwy poleceń i ich dostępność zależą od wersji Syliusa. Sprawdź listę
przez `bin/console list sylius`.

Konsument kolejek to osobna sprawa: powinien działać jako usługa systemowa
(`messenger:consume async -vv` pod supervisorem albo systemd), a nie z crona.
Sprawdzenie `messenger` pilnuje właśnie tego, czy działa.

## Polecenia

| Polecenie | Do czego |
| --- | --- |
| `calmfox:watch:status` | Stan połączenia, adres kontrolny, obie sekcje sprawdzeń |
| `calmfox:watch:register <email>` | Zakłada konto Free i łączy sklep |
| `calmfox:watch:pair [token]` | Łączy z istniejącą stroną w panelu |
| `calmfox:watch:disconnect` | Kończy monitoring wnętrza i mówi o tym panelowi |
| `calmfox:watch:updates` | Liczy zaległe aktualizacje, do crona |
| `calmfox:watch:heartbeat` | Znacznik dla sprawdzenia zadań cyklicznych, do crona |
| `calmfox:watch:health [--section=security]` | Wypisuje payload lokalnie |

## Co sprawdzamy

### Sekcja `health` (monitoring pyta co minutę)

| Identyfikator | Co sprawdza |
| --- | --- |
| `db` | Zapytanie kontrolne przez Doctrine, z pomiarem czasu. Brak bazy to `fail`, nie wyjątek. |
| `disk` | Prawo zapisu do `var/` i katalogu mediów, rozmiar instalacji, zajętość względem podanego limitu konta. |
| `smtp` | Połączenie z serwerem poczty (TCP, powitanie 220, EHLO). To test POŁĄCZENIA, nie doręczenia. |
| `messenger` | Zaległości w kolejkach doctrine: wiek najstarszej wiadomości i liczba nieudanych. |
| `app_cache` | Zapis i odczyt klucza kontrolnego w puli pamięci podręcznej aplikacji. |
| `checkout` | Czy w każdym włączonym kanale da się kupić: metoda płatności, metoda dostawy, strefa. |
| `scheduled_tasks` | Świeżość znacznika bicia serca, czyli czy cron w ogóle działa. |
| `elasticsearch` | Stan klastra. Opcjonalny: bez podanego adresu check w ogóle nie powstaje. |

### Sekcja `security` (monitoring pyta raz na dobę)

`admin_count`, `admin_login`, `app_env`, `debug_display`, `https`,
`php_version`, `config_perms`, `dir_perms`, `app_secret`, `dev_packages`,
`pending_updates`.

To podstawowa higiena, a nie audyt. Nie skanujemy złośliwego kodu, nie liczymy
sum kontrolnych plików platformy i nie robimy kopii zapasowych.

### Czego świadomie nie robimy

- Nie wysyłamy listy pakietów z wersjami. `site.updates` to same liczby, bo
  spis „co i w jakiej wersji" jest gotową mapą dziur dla atakującego. Nazwy
  jadą wyłącznie tam, gdzie są istotą funkcji: w historii zmian wersji oraz
  jako skład WŁĄCZONYCH bundli (`signals.activePlugins`, bez wersji). Ten
  drugi wyjątek jest świadomy i ma cenę: kto zdobędzie sekretny adres
  kontrolny, zobaczy listę bundli. Bez nazw zdarzenie o zdjętym rozszerzeniu
  brzmiałoby „coś się zmieniło", a wtedy nie da się na nie zareagować.
- Nie udajemy automatycznych aktualizacji. Sylius ich nie ma, więc pole
  `signals.autoUpdates` w ogóle nie jedzie, zamiast wieźć wartość, która
  znaczyłaby „sprawdzone".
- Nie wysyłamy loginów. Zamiast nich jedzie liczba kont administracyjnych
  i jednokierunkowy odcisk ich zbioru, solony sekretem instalacji. Panel
  wykrywa ZMIANĘ składu, nie tożsamość.
- Nie zaglądamy w dane sprzedażowe. Sprawdzenie `checkout` patrzy wyłącznie
  na konfigurację kanału, nigdy na zamówienia ani obroty.
- Nie zgadujemy liczb, których nie znamy. Dopóki nikt nie uruchomił
  `calmfox:watch:updates`, pole `updates` nie jedzie w ogóle. Zero znaczyłoby
  „sprawdzone, nie ma czego aktualizować", a to byłaby nieprawda.

## Własne sprawdzenia

Usługi, o których wie tylko właściciel sklepu (broker kolejek, integracja
z magazynem, demon synchronizacji cen), dopina się własną klasą:

```php
namespace App\Monitoring;

use Calmfox\WatchBundle\Check\HealthCheckInterface;
use Calmfox\WatchBundle\Core\CheckResult;

final class WarehouseCheck implements HealthCheckInterface
{
    public function run(): ?CheckResult
    {
        $start = microtime(true);
        $socket = @fsockopen('127.0.0.1', 5672, $errno, $error, 2);
        $ms = (int) round((microtime(true) - $start) * 1000);

        if (!\is_resource($socket)) {
            return CheckResult::fail('warehouse', 'Integracja z magazynem', 'Usługa nie przyjmuje połączeń.', $ms);
        }
        fclose($socket);

        return CheckResult::ok('warehouse', 'Integracja z magazynem', null, $ms);
    }
}
```

Przy włączonym `autoconfigure` to wszystko. Bez niego dopisz tag:

```yaml
services:
    App\Monitoring\WarehouseCheck:
        tags: [{ name: calmfox_watch.health_check, priority: 0 }]
```

Dwie zasady, obie z kontraktu:

1. Zwróć `null`, gdy sprawdzenie nie dotyczy tej instalacji. Nie wysyłamy
   „ok" o czymś, czego nie ma.
2. Trzymaj krótki, twardy limit czasu. Adres kontrolny odpowiada co minutę
   i nie może zamulić sklepu.

Identyfikator spoza katalogu parametrów trafi do panelu z etykietą z payloadu
i notką „usługa dopięta własnym rozszerzeniem".

## Historia zmian wersji

Sylius nie ma haka aktualizacji: wdrożenie robi Composer, zwykle z zupełnie
innej maszyny. Dlatego pakiet porównuje migawki `vendor/composer/installed.php`
(plus wersję PHP) przy każdym budowaniu sekcji `security`, czyli najwyżej co
dziesięć minut, oraz przy `calmfox:watch:updates`.

Konsekwencje, które trzeba znać:

- Znacznik `at` to **czas wykrycia** zmiany, a nie czas wdrożenia. Zwykle
  różnią się o minuty, przy sklepie bez ruchu może być więcej. Do zdania
  „awaria zaczęła się po aktualizacji pakietu X" to wystarcza, do rozliczania
  wdrożeń co do sekundy nie.
- Historia zaczyna się od instalacji pakietu. Wcześniejszych zmian nie da się
  odtworzyć, bo nie ma z czego.
- Zmiana `sylius/sylius` albo PHP zapisuje się jako `core`, pozostałe pakiety
  jako `plugin`. Bufor to 200 wpisów.
- Przy wdrożeniach z katalogiem na wydanie historia wymaga współdzielonego
  katalogu stanu (patrz wyżej). Bez tego każde wdrożenie zaczyna ją od nowa.

## Prywatność

Do Calmfox jedzie: domena sklepu, podany adres e-mail (tylko przy zakładaniu
konta) i dane diagnostyczne opisane wyżej: statusy sprawdzeń z opisami, wersje
platformy i PHP, liczby zaległych aktualizacji, liczba i odcisk kont
administracyjnych oraz historia zmian wersji pakietów. Żadnych treści sklepu,
zamówień, klientów, loginów ani haseł.

## Testy

Rdzeń pakietu (`src/Core`) jest wolny od Symfony i Syliusa: to zwykłe klasy PHP.
Dzięki temu kontrakt z panelem da się przetestować bez kontenera i bez bazy.
Zestaw testów działa samodzielnie:

```bash
composer install
vendor/bin/phpunit
```

Jedyny wyjątek to test zgodności podpisu z weryfikacją po stronie serwera
Calmfox Watch. Sięga po kod serwera, który nie jest publiczny, więc bez niego
sam się pomija; pozostałe testy sprawdzają podpis własnym kodem pakietu. Kto
ma dostęp do kodu serwera, wskazuje jego katalog zmienną `CALMFOX_HUB_DIR`:

```bash
CALMFOX_HUB_DIR=/path/to/calmfox-watch-server vendor/bin/phpunit
```

Testy pilnują między innymi: agregacji `ok`/`warn`/`fail`, odrzucenia złego
klucza, ważności poprzedniego sekretu w oknie rotacji i jej wygaśnięcia,
zgodności podpisu z weryfikacją po stronie panelu, pominięcia pola `updates`
przy braku danych, porównywania migawek wersji oraz rozbioru `MAILER_DSN`.

Przykładowe odpowiedzi obu sekcji leżą w `docs/sample-health.json`
i `docs/sample-security.json`. Powstają z tego samego kodu, który odpowiada
monitoringowi, a test pilnuje, żeby się nie rozjechały. Regeneracja po
świadomej zmianie kontraktu:

```bash
CALMFOX_WRITE_SAMPLES=1 vendor/bin/phpunit --filter SamplePayloads
```

## Granice ochrony

Odpowiedź podpisujemy kluczem instalacji (HMAC-SHA256 nad znacznikiem
jednorazowym, czasem wygenerowania i dokładnymi bajtami treści). To odcina
tanie ataki: podstawiony plik statyczny, odpowiedź z pamięci podręcznej,
powtórkę sprzed przejęcia sklepu. Kto ma pełną kontrolę nad serwerem, ma też
sekret i potrafi podpisać kłamstwo. Podpis nie zastępuje odzyskiwania serwera
i tak o nim mówimy.

## Licencja

MIT, zobacz [LICENSE](LICENSE).
