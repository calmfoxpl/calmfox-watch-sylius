<?php

declare(strict_types=1);

namespace Calmfox\WatchBundle\History;

use Calmfox\WatchBundle\Core\InstalledPackages;
use Calmfox\WatchBundle\Core\StateStore;
use Calmfox\WatchBundle\Core\VersionSnapshot;

/**
 * Historia zmian wersji. Sylius nie ma haka aktualizacji (wdrożenie robi
 * Composer, zwykle poza aplikacją), więc porównujemy migawki spisu pakietów.
 * Uzgodnienie odpala się przy budowaniu sekcji `security` (czyli najwyżej
 * co dziesięć minut, bo tyle żyje jej pamięć podręczna) oraz z poleceń CLI.
 *
 * Pierwsze uruchomienie zapisuje wyłącznie migawkę i nie tworzy wpisów:
 * historia zaczyna się od instalacji pakietu, wstecz nie da się jej odtworzyć.
 */
final class UpdateHistory
{
    private const HISTORY_KEY = 'history';
    private const SNAPSHOT_KEY = 'versions';
    private const RECONCILED_AT = 'versionsCheckedAt';
    private const EVERY = 600;

    /** @param list<string> $platformPackages */
    public function __construct(
        private readonly StateStore $state,
        private readonly string $vendorDir,
        private readonly array $platformPackages = VersionSnapshot::PLATFORM_PACKAGES,
    ) {
    }

    /** @return list<array<string, mixed>> najnowsze pierwsze */
    public function all(): array
    {
        $stored = $this->state->get(self::HISTORY_KEY, []);

        return \is_array($stored) ? array_values($stored) : [];
    }

    /**
     * @return int liczba nowych wpisów
     */
    public function reconcile(bool $force = false): int
    {
        $checkedAt = (int) $this->state->get(self::RECONCILED_AT, 0);
        if (!$force && time() - $checkedAt < self::EVERY) {
            return 0;
        }

        $current = InstalledPackages::withPlatform(InstalledPackages::load($this->vendorDir));
        if ([] === $current) {
            return 0; // brak spisu pakietów: nie ma z czym porównywać i nie zmyślamy
        }

        $previous = $this->state->get(self::SNAPSHOT_KEY);
        if (!\is_array($previous) || [] === $previous) {
            $this->state->set([self::SNAPSHOT_KEY => $current, self::RECONCILED_AT => time()]);

            return 0;
        }

        // `at` to czas WYKRYCIA zmiany, nie czas wdrożenia. Piszemy o tym w README,
        // bo różnica bywa istotna przy sklepach bez ruchu.
        $entries = VersionSnapshot::diff($previous, $current, gmdate('c'), $this->platformPackages);

        $this->state->set([
            self::SNAPSHOT_KEY => $current,
            self::RECONCILED_AT => time(),
            self::HISTORY_KEY => VersionSnapshot::merge($entries, $this->all()),
        ]);

        return \count($entries);
    }

    /** @return array<string, string> */
    public function snapshot(): array
    {
        $stored = $this->state->get(self::SNAPSHOT_KEY, []);

        return \is_array($stored) ? $stored : [];
    }
}
