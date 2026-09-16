<?php

declare(strict_types=1);

namespace Calmfox\WatchBundle\Payload;

use Calmfox\WatchBundle\CalmfoxWatchBundle;
use Calmfox\WatchBundle\Check\CheckRunner;
use Calmfox\WatchBundle\Core\FileCache;
use Calmfox\WatchBundle\Core\InstalledPackages;
use Calmfox\WatchBundle\Core\PayloadBuilder;
use Calmfox\WatchBundle\Core\StateStore;
use Calmfox\WatchBundle\History\UpdateHistory;
use Calmfox\WatchBundle\Signals\AdminSignals;
use Calmfox\WatchBundle\Signals\PackageSignals;

/**
 * Złożenie odpowiedzi obu sekcji razem z pamięcią podręczną: `health` 60 s,
 * `security` 10 minut. Bez tego sonda odpytująca co minutę kazałaby sklepowi
 * liczyć rozmiar katalogu i pukać do serwera poczty przy każdym sprawdzeniu.
 *
 * Pamięć podręczna leży w plikach obok stanu, nie w puli aplikacji: adres
 * kontrolny ma odpowiadać także wtedy, gdy padnie Redis albo baza.
 */
final class PayloadProvider
{
    public const SECTION_HEALTH = 'health';
    public const SECTION_SECURITY = 'security';

    private const HEALTH_TTL = 60;
    private const SECURITY_TTL = 600;

    public function __construct(
        private readonly CheckRunner $healthChecks,
        private readonly CheckRunner $securityChecks,
        private readonly AdminSignals $signals,
        private readonly PackageSignals $packages,
        private readonly UpdateHistory $history,
        private readonly StateStore $state,
        private readonly FileCache $cache,
        private readonly string $vendorDir,
        private readonly string $platformPackage,
    ) {
    }

    /** @return array<string, mixed> */
    public function payload(string $section, bool $fresh = false): array
    {
        $section = self::SECTION_SECURITY === $section ? self::SECTION_SECURITY : self::SECTION_HEALTH;
        $key = 'payload-'.$section;

        if (!$fresh) {
            $cached = $this->cache->get($key);
            if (\is_array($cached) && isset($cached['status'])) {
                return $cached;
            }
        }

        if (self::SECTION_SECURITY === $section) {
            // Sekcja security i tak liczy się drożej, więc to najtańsze miejsce
            // na uzgodnienie migawki wersji.
            $this->history->reconcile();
            $result = $this->securityChecks->run();
            $payload = PayloadBuilder::security(CalmfoxWatchBundle::VERSION, $result['checks'], $this->history->all());
            $this->cache->set($key, $payload, self::SECURITY_TTL);

            return $payload;
        }

        $result = $this->healthChecks->run();
        $payload = PayloadBuilder::health(
            CalmfoxWatchBundle::VERSION,
            $result['checks'],
            PayloadBuilder::site($this->platformVersion(), \PHP_VERSION, CalmfoxWatchBundle::VERSION, $this->updates()),
            // Dwa zestawy sygnałów w jednym polu `signals`: konta z pełnym dostępem
            // i skład włączonych rozszerzeń. Hub porównuje je osobno (WpAdminWatcher
            // i WpPluginWatcher), ale odczyt jest jeden, bo to jedno odpytanie.
            array_merge($this->signals->signals(), $this->packages->signals())
        );
        $this->cache->set($key, $payload, self::HEALTH_TTL);

        return $payload;
    }

    public function forget(): void
    {
        $this->cache->delete('payload-'.self::SECTION_HEALTH);
        $this->cache->delete('payload-'.self::SECTION_SECURITY);
    }

    private function platformVersion(): ?string
    {
        $packages = InstalledPackages::load($this->vendorDir);

        // Projekty na rozbitych pakietach nie mają metapakietu, ale mają rdzeń.
        foreach ([$this->platformPackage, 'sylius/core-bundle'] as $name) {
            if (isset($packages[$name])) {
                // Composer podaje wersje Syliusa z przedrostkiem („v2.2.1"), a panel
                // skleja to z nazwą platformy i pokazywał „Sylius v2.2.1". Neos i
                // WordPress podają same cyfry, więc równamy do nich. Ucinamy TYLKO
                // w tym, co jedzie do panelu: wersje w migawce zostają surowe, żeby
                // istniejące instalacje nie zobaczyły zmiany wersji tam, gdzie jej nie było.
                return ltrim($packages[$name], 'vV');
            }
        }

        return null;
    }

    /**
     * Liczby zaległych aktualizacji albo `null`, gdy nikt ich nie policzył.
     * `null` oznacza pominięcie CAŁEGO pola `updates` w payloadzie, zgodnie
     * z kontraktem: zero to informacja „sprawdzone", a nie „nie wiemy".
     *
     * @return array{core: int, plugins: int, themes: int}|null
     */
    private function updates(): ?array
    {
        $stored = $this->state->get('updates');
        if (!\is_array($stored) || !isset($stored['plugins'])) {
            return null;
        }

        return [
            'core' => (int) ($stored['core'] ?? 0),
            'plugins' => (int) ($stored['plugins'] ?? 0),
            'themes' => 0,
        ];
    }
}
