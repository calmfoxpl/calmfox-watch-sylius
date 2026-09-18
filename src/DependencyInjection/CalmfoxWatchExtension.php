<?php

declare(strict_types=1);

namespace Calmfox\WatchBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

final class CalmfoxWatchExtension extends Extension implements PrependExtensionInterface
{
    /** Szablon wpinający kafelek w pulpit. Renderuje podzapytanie do naszej akcji. */
    private const DASHBOARD_TEMPLATE = '@CalmfoxWatch/dashboard.html.twig';

    /**
     * Kafelek na pulpicie panelu dopinamy sami, bo inaczej trzeba by kazać
     * właścicielowi sklepu edytować konfigurację po instalacji, a monitoring,
     * o którym trzeba pamiętać, nie jest monitoringiem.
     *
     * Sylius 1.x i 2.x mają na to dwa różne mechanizmy i deklarujemy oba.
     * Nazwy miejsc są sprawdzone w kodzie Syliusa: zdarzenie szablonu pulpitu
     * w 1.x to `sylius.admin.dashboard.content`, hook w 2.x to
     * `sylius_admin.dashboard.index.content` (tam priorytet 150 wchodzi między
     * nagłówek 200 a statystyki 100, czyli nad wykresy sprzedaży).
     *
     * O tym, którą gałąź wybrać, NIE rozstrzyga obecność SyliusUiBundle i nie
     * wolno tego uprościć z powrotem: w Syliusie 2.x ten bundel nadal jest
     * wpięty, ale jego konfiguracja ma już tylko `twig_ux`. Zadeklarowanie
     * `events` wywraca wtedy budowanie kontenera („Unrecognized option
     * \"events\" under \"sylius_ui\"”), czyli nie psuje kafelka — kładzie
     * CAŁY sklep, razem ze sklepem dla kupujących, bo żadna strona się nie
     * zbuduje. Rozstrzyga dopiero brak bundla z hookami: ma go wyłącznie 2.x.
     */
    public function prepend(ContainerBuilder $container): void
    {
        if (!$this->dashboardWidgetEnabled($container)) {
            return;
        }

        $bundles = (array) $container->getParameter('kernel.bundles');

        if (isset($bundles['SyliusUiBundle']) && !isset($bundles['SyliusTwigHooksBundle'])) {
            $container->prependExtensionConfig('sylius_ui', ['events' => [
                'sylius.admin.dashboard.content' => ['blocks' => [
                    'calmfox_watch' => ['template' => self::DASHBOARD_TEMPLATE, 'priority' => 10],
                ]],
            ]]);
        }

        if (isset($bundles['SyliusTwigHooksBundle'])) {
            $container->prependExtensionConfig('sylius_twig_hooks', ['hooks' => [
                'sylius_admin.dashboard.index.content' => [
                    'calmfox_watch' => ['template' => self::DASHBOARD_TEMPLATE, 'priority' => 150],
                ],
            ]]);
        }
    }

    /**
     * Ustawienia pakietu nie są jeszcze przetworzone, gdy działa prepend(),
     * więc czytamy surowe wpisy z konfiguracji projektu. Wyłączenie kafelka
     * ma działać od razu, a nie po wyczyszczeniu połowy kontenera.
     */
    private function dashboardWidgetEnabled(ContainerBuilder $container): bool
    {
        foreach ($container->getExtensionConfig($this->getAlias()) as $config) {
            if (\is_array($config) && \array_key_exists('dashboard_widget', $config)) {
                return (bool) $config['dashboard_widget'];
            }
        }

        return true;
    }

    public function getAlias(): string
    {
        return 'calmfox_watch';
    }

    /** @param array<array<string, mixed>> $configs */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration(new Configuration(), $configs);
        $projectDir = (string) $container->getParameter('kernel.project_dir');

        // Zmienne środowiskowe czytamy tutaj, a nie przez %env()%, bo katalog stanu
        // i adres API muszą być znane także w CLI i przy budowie kontenera.
        // Konsekwencja: po ich zmianie trzeba wyczyścić pamięć podręczną kontenera.
        $container->setParameter('calmfox_watch.state_dir', $config['state_dir'] ?? self::env('CALMFOX_WATCH_STATE_DIR') ?? $projectDir.'/var/calmfox-watch');
        // Przeprowadzka panelu na watch.calmfox.net: adres z config/packages sklepu bywa
        // wpisany na sztywno z czasów parowania. Stary host oddaje 308, więc podmiana nie
        // naprawia awarii — zdejmuje skok przy każdym żądaniu i adres, którego już nie
        // używamy, z panelu administratora.
        $apiUrl = rtrim($config['api_url'] ?? self::env('CALMFOX_WATCH_API_URL') ?? 'https://watch.calmfox.net', '/');
        $container->setParameter('calmfox_watch.api_url', 'https://watch.calmfox.pl' === $apiUrl ? 'https://watch.calmfox.net' : $apiUrl);
        $container->setParameter('calmfox_watch.site_url', null !== $config['site_url'] ? rtrim((string) $config['site_url'], '/') : null);
        $container->setParameter('calmfox_watch.admin_path', trim((string) $config['admin_path'], '/'));
        $container->setParameter('calmfox_watch.admin_layout', $config['admin_layout']);
        $container->setParameter('calmfox_watch.disk_quota_gb', (float) $config['disk_quota_gb']);
        $container->setParameter('calmfox_watch.media_dir', $config['media_dir'] ?? $projectDir.'/public/media');
        $container->setParameter('calmfox_watch.var_dir', $projectDir.'/var');
        $container->setParameter('calmfox_watch.vendor_dir', $projectDir.'/vendor');
        $container->setParameter('calmfox_watch.project_dir', $projectDir);
        $container->setParameter('calmfox_watch.mailer_dsn', $config['mailer_dsn'] ?? self::env('MAILER_DSN'));
        $container->setParameter('calmfox_watch.elasticsearch_url', $config['elasticsearch_url'] ?? self::env('ELASTICSEARCH_URL'));
        $container->setParameter('calmfox_watch.messenger_table', $config['messenger_table']);
        $container->setParameter('calmfox_watch.failure_transports', $config['failure_transports']);
        $container->setParameter('calmfox_watch.heartbeat_interval', $config['heartbeat_interval']);
        $container->setParameter('calmfox_watch.platform_package', $config['platform_package']);
        $container->setParameter('calmfox_watch.composer_binary', $config['composer_binary']);
        $container->setParameter('calmfox_watch.dashboard_widget', (bool) $config['dashboard_widget']);

        (new YamlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config')))->load('services.yaml');
    }

    private static function env(string $name): ?string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

        return \is_string($value) && '' !== $value ? $value : null;
    }
}
