<?php

declare(strict_types=1);

namespace Calmfox\WatchBundle\Check\Health;

use Calmfox\WatchBundle\Check\HealthCheckInterface;
use Calmfox\WatchBundle\Core\Bytes;
use Calmfox\WatchBundle\Core\CheckResult;
use Calmfox\WatchBundle\Core\DiskLimit;
use Calmfox\WatchBundle\Core\FileCache;
use Calmfox\WatchBundle\Core\InstallSize;
use Calmfox\WatchBundle\Core\StateStore;

/**
 * Miejsce na dysku. Kolejność źródeł jest ta sama, co we wtyczce WordPressa
 * i wynika z jednej zasady: nie podajemy liczby, która nie dotyczy konta klienta.
 * 1) limit podany przez klienta, 2) wiarygodny odczyt systemowy (własny serwer),
 * 3) uczciwe „hosting nie pokazuje limitu tego konta". Zawsze z realnie
 * policzonym rozmiarem instalacji.
 *
 * Brak zapisu do var/ albo do katalogu mediów to `fail`: sklep, który nie może
 * zapisać pamięci podręcznej ani zdjęcia produktu, przestaje działać w ciągu minut.
 */
final class DiskCheck implements HealthCheckInterface
{
    private const LABEL = 'Miejsce na dysku';
    private const CACHE_KEY = 'install-size';

    public function __construct(
        private readonly string $projectDir,
        private readonly string $varDir,
        private readonly string $mediaDir,
        private readonly float $configuredQuotaGb,
        private readonly StateStore $state,
        private readonly FileCache $cache,
    ) {
    }

    public function run(): ?CheckResult
    {
        $unwritable = [];
        foreach (['var/' => $this->varDir, 'katalog mediów' => $this->mediaDir] as $name => $dir) {
            if (is_dir($dir) && !is_writable($dir)) {
                $unwritable[] = $name;
            }
        }
        if ([] !== $unwritable) {
            return CheckResult::fail('disk', self::LABEL, sprintf(
                'Brak prawa zapisu: %s. Sklep nie zapisze pamięci podręcznej ani plików wgrywanych w panelu.',
                implode(', ', $unwritable)
            ));
        }

        $size = $this->installSize();
        $used = (float) $size['bytes'];
        $usedLabel = sprintf('Sklep zajmuje %s%s.', Bytes::format($used), $size['complete'] ? '' : ' (pomiar przerwany na limicie czasu, liczba jest zaniżona)');

        // 1) Limit konta podany przez klienta: na hostingu współdzielonym to jedyna pewna liczba.
        $quota = $this->quotaGb();
        if ($quota > 0) {
            $limit = $quota * Bytes::GB;
            $percent = DiskLimit::percentUsed($used, $limit);

            return CheckResult::of(DiskLimit::statusForQuota($used, $limit), 'disk', self::LABEL, sprintf(
                '%s To %d%% z podanego limitu konta %s. Poza tym miejsce zajmują poczta i pozostałe strony na koncie.',
                $usedLabel,
                $percent,
                Bytes::format($limit)
            ));
        }

        $free = @disk_free_space($this->projectDir);
        $total = @disk_total_space($this->projectDir);

        // 2) Odczyt systemowy tylko wtedy, gdy naprawdę dotyczy tego konta.
        if (false !== $free && false !== $total && $total > 0
            && !DiskLimit::readingIsShared((float) $total, DiskLimit::markersPresent(), (string) ini_get('open_basedir'))) {
            return CheckResult::of(DiskLimit::statusForFree((float) $free, (float) $total), 'disk', self::LABEL, sprintf(
                'Wolne %s z %s (%d%%). %s',
                Bytes::format((float) $free),
                Bytes::format((float) $total),
                (int) floor($free / $total * 100),
                $usedLabel
            ));
        }

        // 3) Hosting współdzielony bez limitu od klienta: mówimy wprost, czego nie wiemy.
        return CheckResult::ok('disk', self::LABEL, sprintf(
            '%s Hosting nie pokazuje limitu tego konta (widzimy tylko wspólny dysk serwera), więc nie liczymy zajętości. Podaj limit na ekranie Calmfox Watch, a będziemy go pilnować.',
            $usedLabel
        ));
    }

    /** Wartość z ekranu w panelu sklepu wygrywa z wartością z konfiguracji pakietu. */
    private function quotaGb(): float
    {
        $stored = $this->state->get('diskQuotaGb');

        return \is_numeric($stored) && (float) $stored > 0 ? (float) $stored : $this->configuredQuotaGb;
    }

    /** @return array{bytes: int, files: int, complete: bool} */
    private function installSize(): array
    {
        $cached = $this->cache->get(self::CACHE_KEY);
        if (\is_array($cached) && isset($cached['bytes'])) {
            return ['bytes' => (int) $cached['bytes'], 'files' => (int) ($cached['files'] ?? 0), 'complete' => (bool) ($cached['complete'] ?? true)];
        }

        $size = InstallSize::measure($this->projectDir);
        $this->cache->set(self::CACHE_KEY, $size, InstallSize::TTL);

        return $size;
    }
}
