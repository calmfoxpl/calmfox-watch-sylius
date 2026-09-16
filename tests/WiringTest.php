<?php

declare(strict_types=1);

namespace Calmfox\WatchBundle\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Kontener Syliusa buduje się dopiero w sklepie, więc literówka w nazwie trasy
 * albo w identyfikatorze usługi wychodzi na żywej instalacji, po wdrożeniu.
 * Ten test sprawdza to, co da się sprawdzić bez frameworka: czy pliki
 * konfiguracji i szablony mówią o tych samych nazwach.
 *
 * Klasy sprawdzamy przez PLIK, a nie class_exists: załadowanie kontrolera
 * wciągnęłoby Symfony, którego samodzielne testy rdzenia nie mają.
 */
final class WiringTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function routes(): array
    {
        return Yaml::parseFile(\dirname(__DIR__).'/src/Resources/config/routes.yaml');
    }

    private static function source(string $relative): string
    {
        $path = \dirname(__DIR__).'/'.$relative;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function testEveryRoutePointsToAnExistingControllerMethod(): void
    {
        $controllers = [];
        foreach (self::routes() as $name => $route) {
            self::assertArrayHasKey('controller', $route, sprintf('trasa %s bez kontrolera', $name));
            $controllers[] = (string) $route['controller'];
        }

        $admin = self::source('src/Controller/AdminController.php');
        foreach ($controllers as $controller) {
            if (!str_contains($controller, '::')) {
                continue; // HealthController jest wołany jako __invoke
            }
            [, $method] = explode('::', $controller, 2);
            self::assertStringContainsString(sprintf('public function %s(', $method), $admin,
                sprintf('trasa wskazuje akcję %s, której nie ma w kontrolerze', $method));
        }
    }

    /** Łączenie przez panel: trasa akcji, ekran łączenia i znacznik jednorazowy. */
    public function testConnectFlowIsWiredEndToEnd(): void
    {
        $routes = self::routes();
        self::assertArrayHasKey('calmfox_watch_admin_connect', $routes);
        self::assertSame(['POST'], $routes['calmfox_watch_admin_connect']['methods'],
            'wyjście do panelu zmienia stan (pali znacznik), więc nie ma prawa być zwykłym GET-em');

        $admin = self::source('src/Controller/AdminController.php');
        self::assertStringContainsString("'/polacz/sylius?'", $admin,
            'ekran łączenia w panelu ma człon platformy, inaczej sklep trafi na formularz WordPressa');
        self::assertStringContainsString('makeConnectState()', $admin);
        self::assertStringContainsString('consumeConnectState(', $admin);

        $secrets = self::source('src/Core/SecretManager.php');
        self::assertStringContainsString('hash_equals($expected, $candidate)', $secrets,
            'znacznik połączenia porównujemy w stałym czasie');
    }

    /** Kafelek na pulpicie: szablon wpinany w pulpit musi wołać trasę, która istnieje. */
    public function testDashboardWidgetTemplateCallsAnExistingRoute(): void
    {
        $dashboard = self::source('src/Resources/views/dashboard.html.twig');
        self::assertStringContainsString("path('calmfox_watch_admin_widget')", $dashboard);
        self::assertArrayHasKey('calmfox_watch_admin_widget', self::routes());

        $extension = self::source('src/DependencyInjection/CalmfoxWatchExtension.php');
        self::assertStringContainsString("'@CalmfoxWatch/dashboard.html.twig'", $extension);
        self::assertStringContainsString("'sylius.admin.dashboard.content'", $extension, 'wpięcie w pulpit Syliusa 1.x');
        self::assertStringContainsString("'sylius_admin.dashboard.index.content'", $extension, 'wpięcie w pulpit Syliusa 2.x');
    }

    public function testServicesDeclareEverythingTheScreenAndPayloadNeed(): void
    {
        // PARSE_CUSTOM_TAGS, bo plik usług używa !tagged_iterator: bez flagi
        // parser wywala się na tagu, którego nie zna, a to nie jest błąd pliku.
        $services = Yaml::parseFile(\dirname(__DIR__).'/src/Resources/config/services.yaml', Yaml::PARSE_CUSTOM_TAGS)['services'];

        self::assertArrayHasKey('calmfox_watch.package_signals', $services);
        self::assertSame('%kernel.bundles%', $services['calmfox_watch.package_signals']['arguments'][0],
            'skład bundli czytamy z parametru kontenera, nie z pliku bundles.php');
        self::assertContains('@calmfox_watch.package_signals', $services['calmfox_watch.payloads']['arguments'],
            'sygnały składu rozszerzeń muszą trafić do payloadu, inaczej hub nie ma czego porównywać');
        self::assertSame('%kernel.bundles%', $services['calmfox_watch.menu_listener']['arguments'][0],
            'pozycja w menu dobiera nazwę ikony po wersji Syliusa, a tę rozpoznaje po bundlach');
    }
}
