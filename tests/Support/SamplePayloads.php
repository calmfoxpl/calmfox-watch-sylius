<?php

declare(strict_types=1);

namespace Calmfox\WatchBundle\Tests\Support;

use Calmfox\WatchBundle\Core\AdminFingerprint;
use Calmfox\WatchBundle\Core\CheckNormalizer;
use Calmfox\WatchBundle\Core\CheckResult;
use Calmfox\WatchBundle\Core\PayloadBuilder;

/**
 * Próbki payloadu do docs/. Wyniki checków są ustalone (nie mamy tu bazy ani
 * sklepu), ale SKŁADANIE odpowiedzi robi ten sam kod, który odpowiada hubowi:
 * normalizacja, agregacja statusu, odcisk kont i budowa pól. Dzięki temu
 * próbka nie może rozjechać się z kontraktem bez zapalenia testu.
 */
final class SamplePayloads
{
    public const VERSION = '1.0.0';
    private const SECRET = '0123456789abcdef0123456789abcdef';

    /**
     * Skrócona lista bundli typowego sklepu. W próbce nie ma sensu wypisywać
     * pełnych czterdziestu, bo pokazujemy KSZTAŁT pola, a nie inwentarz.
     *
     * @var list<string>
     */
    private const BUNDLES = [
        'CalmfoxWatchBundle',
        'DoctrineBundle',
        'FrameworkBundle',
        'SecurityBundle',
        'SyliusAdminBundle',
        'SyliusCoreBundle',
        'SyliusShopBundle',
        'TwigBundle',
    ];

    /** @return array<string, mixed> */
    public static function health(): array
    {
        $checks = CheckNormalizer::normalize([
            CheckResult::ok('db', 'Baza danych', null, 3),
            CheckResult::warn('disk', 'Miejsce na dysku', 'Sklep zajmuje 17,4 GB. To 86% z podanego limitu konta 20,0 GB. Poza tym miejsce zajmują poczta i pozostałe strony na koncie.'),
            CheckResult::ok('smtp', 'Wysyłka e-mail (SMTP)', 'Serwer smtp.example.com:587 przyjmuje połączenia. To test połączenia, nie doręczenia wiadomości.', 128),
            CheckResult::ok('messenger', 'Kolejki zadań', 'W kolejce czekają 4 wiadomości, najstarsza od 41 s.', 7),
            CheckResult::ok('app_cache', 'Pamięć podręczna aplikacji (Redis)', null, 2),
            CheckResult::ok('checkout', 'Ścieżka zakupowa', 'Każdy z 2 włączonych kanałów ma czynną metodę płatności i dostawy.', 11),
            CheckResult::ok('scheduled_tasks', 'Zadania cykliczne', 'Ostatnie wywołanie 4 min temu.'),
            CheckResult::ok('elasticsearch', 'Elasticsearch', 'Stan klastra: green.', 22),
        ]);

        return PayloadBuilder::health(
            self::VERSION,
            $checks,
            PayloadBuilder::site('1.13.2', '8.3.14', self::VERSION, ['core' => 0, 'plugins' => 6, 'themes' => 0]),
            [
                'adminCount' => 3,
                'adminsFingerprint' => AdminFingerprint::of(['1:admin.sklep', '4:magazyn', '9:marketing'], self::SECRET),
                'newestAdminAt' => '2026-07-30T09:12:00+00:00',
                'pluginCount' => \count(self::BUNDLES),
                'pluginsFingerprint' => AdminFingerprint::of(self::BUNDLES, self::SECRET),
                'activePlugins' => self::BUNDLES,
                // `autoUpdates` nie ma i nie będzie: Sylius nie aktualizuje się sam,
                // a wartość w tym polu znaczyłaby „sprawdzone".
            ]
        );
    }

    /** @return array<string, mixed> */
    public static function security(): array
    {
        $checks = CheckNormalizer::normalize([
            CheckResult::ok('admin_count', 'Liczba administratorów', 'Włączonych kont administracyjnych: 3.'),
            CheckResult::ok('admin_login', 'Konta o domyślnym loginie', 'Brak kont o domyślnych loginach.'),
            CheckResult::ok('app_env', 'Tryb pracy aplikacji', 'Tryb produkcyjny, bez trybu diagnostycznego.'),
            CheckResult::ok('debug_display', 'Ujawnianie szczegółów błędów', 'Błędy nie są wyświetlane odwiedzającym, profiler niedostępny.'),
            CheckResult::ok('https', 'Szyfrowanie HTTPS'),
            CheckResult::warn('php_version', 'Wersja PHP', 'PHP 8.2.20 dostaje już tylko poprawki bezpieczeństwa. Zaplanuj przejście wyżej.'),
            CheckResult::ok('config_perms', 'Uprawnienia plików konfiguracji', 'Sprawdzone pliki (2) nie są zapisywalne dla innych.'),
            CheckResult::ok('dir_perms', 'Uprawnienia katalogów', 'Katalogi zapisywalne wyłącznie dla właściciela.'),
            CheckResult::ok('app_secret', 'Klucz aplikacji (APP_SECRET)', 'Klucz aplikacji jest własny i odpowiednio długi.'),
            CheckResult::warn('dev_packages', 'Pakiety deweloperskie', 'W wydaniu produkcyjnym są pakiety deweloperskie: symfony/web-profiler-bundle. Wdrażaj poleceniem composer install --no-dev.'),
            CheckResult::ok('pending_updates', 'Zaległe aktualizacje', 'Pakietów do aktualizacji: 6.'),
        ]);

        return PayloadBuilder::security(self::VERSION, $checks, [
            [
                'kind' => 'plugin',
                'name' => 'sylius/paypal-plugin',
                'from' => '2.0.1',
                'to' => '2.0.2',
                'at' => '2026-08-18T21:35:00+00:00',
                'mode' => 'manual',
                'by' => null,
            ],
            [
                'kind' => 'core',
                'name' => 'sylius/sylius',
                'from' => '1.13.1',
                'to' => '1.13.2',
                'at' => '2026-08-18T21:35:00+00:00',
                'mode' => 'manual',
                'by' => null,
            ],
            [
                'kind' => 'core',
                'name' => 'PHP',
                'from' => '8.1.29',
                'to' => '8.2.20',
                'at' => '2026-06-02T04:11:00+00:00',
                'mode' => 'manual',
                'by' => null,
            ],
        ]);
    }
}
