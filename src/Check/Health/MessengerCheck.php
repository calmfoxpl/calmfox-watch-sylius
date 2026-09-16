<?php

declare(strict_types=1);

namespace Calmfox\WatchBundle\Check\Health;

use Calmfox\WatchBundle\Check\HealthCheckInterface;
use Calmfox\WatchBundle\Core\CheckResult;
use Doctrine\DBAL\Connection;

/**
 * Kolejki Symfony Messenger. W sklepie to nie jest szczegół techniczny: tędy
 * idą potwierdzenia zamówień, powiadomienia o wysyłce i przeliczenia, więc
 * martwy konsument oznacza sklep, który przyjmuje pieniądze i milczy.
 *
 * Progi (świadomie oparte na WIEKU, nie na liczbie):
 * - najstarsza nieodebrana wiadomość starsza niż 30 minut = fail. Tyle wystarczy,
 *   żeby stwierdzić, że konsument nie pracuje, a nie że akurat jest szczyt.
 * - starsza niż 5 minut = warn, czyli „nadrabia, popatrzmy".
 * Sam rozmiar kolejki nie jest awarią: przy imporcie cennika bywa duży i mija.
 *
 * Kolejka wiadomości nieudanych daje najwyżej `warn`, nigdy `fail`. Powód jest
 * praktyczny: nieudane wiadomości nie znikają same, więc `fail` zostawiłby
 * stale otwarty incydent, a stale czerwony monitoring uczy ludzi go ignorować.
 * Wiek zaległości goi się sam, gdy konsument wraca, i o to nam chodzi.
 *
 * Gdy tabeli transportu nie ma (Messenger nieużywany albo inny transport),
 * check w ogóle nie powstaje. Nie wysyłamy „ok" o czymś, czego nie ma.
 */
final class MessengerCheck implements HealthCheckInterface
{
    private const LABEL = 'Kolejki zadań';
    private const WARN_AFTER = 300;
    private const FAIL_AFTER = 1800;

    /** @param list<string> $failureTransports */
    public function __construct(
        private readonly ?Connection $connection,
        private readonly string $table,
        private readonly array $failureTransports,
    ) {
    }

    public function run(): ?CheckResult
    {
        if (null === $this->connection) {
            return null;
        }

        // Nazwa tabeli pochodzi z konfiguracji pakietu, ale wchodzi do zapytania
        // wprost (cytowanie identyfikatorów różni się między DBAL 3 a 4), więc
        // wpuszczamy wyłącznie to, co może być nazwą tabeli.
        if (1 !== preg_match('/^[A-Za-z0-9_]{1,64}$/', $this->table)) {
            return null;
        }

        $start = microtime(true);
        try {
            if (!$this->connection->createSchemaManager()->tablesExist([$this->table])) {
                return null;
            }
            $rows = $this->connection->executeQuery(sprintf(
                'SELECT queue_name, COUNT(*) AS pending, MIN(available_at) AS oldest FROM %s WHERE delivered_at IS NULL GROUP BY queue_name',
                $this->table
            ))->fetchAllAssociative();
        } catch (\Throwable) {
            // Baza już ma własny check i to on ma zapalić się na czerwono.
            return null;
        }

        $pending = 0;
        $failed = 0;
        $oldest = null;
        foreach ($rows as $row) {
            $queue = (string) ($row['queue_name'] ?? '');
            $count = (int) ($row['pending'] ?? 0);
            if (\in_array($queue, $this->failureTransports, true)) {
                $failed += $count;

                continue;
            }
            $pending += $count;
            $age = self::age($row['oldest'] ?? null);
            if (null !== $age && (null === $oldest || $age > $oldest)) {
                $oldest = $age;
            }
        }

        $ms = (int) round((microtime(true) - $start) * 1000);
        $status = CheckResult::OK;
        if (null !== $oldest && $oldest > self::FAIL_AFTER) {
            $status = CheckResult::FAIL;
        } elseif (null !== $oldest && $oldest > self::WARN_AFTER) {
            $status = CheckResult::WARN;
        } elseif ($failed > 0) {
            $status = CheckResult::WARN;
        }

        return CheckResult::of($status, 'messenger', self::LABEL, $this->detail($pending, $oldest, $failed, $status), $ms);
    }

    private function detail(int $pending, ?int $oldest, int $failed, string $status): string
    {
        $parts = [];
        if (0 === $pending) {
            $parts[] = 'Kolejki puste.';
        } else {
            $parts[] = sprintf('W kolejce czeka %d %s, najstarsza od %s.', $pending, self::plural($pending, 'wiadomość', 'wiadomości', 'wiadomości'), self::duration($oldest));
        }
        if ($failed > 0) {
            $parts[] = sprintf('Nieudanych wiadomości: %d. Trzeba je przejrzeć poleceniem messenger:failed:show, same nie znikną.', $failed);
        }
        if (CheckResult::FAIL === $status) {
            $parts[] = 'Tak stare zaległości znaczą zwykle, że konsument kolejek nie działa. Potwierdzenia zamówień nie wychodzą.';
        }

        return implode(' ', $parts);
    }

    private static function age(mixed $value): ?int
    {
        if (!\is_string($value) || '' === $value) {
            return null;
        }
        try {
            // Doctrine zapisuje znaczniki w UTC niezależnie od strefy sklepu.
            $at = new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }

        return max(0, time() - $at->getTimestamp());
    }

    private static function duration(?int $seconds): string
    {
        if (null === $seconds) {
            return 'nieznanego czasu';
        }
        if ($seconds < 120) {
            return sprintf('%d s', $seconds);
        }
        if ($seconds < 7200) {
            return sprintf('%d min', (int) round($seconds / 60));
        }

        return sprintf('%d godz.', (int) round($seconds / 3600));
    }

    private static function plural(int $count, string $one, string $few, string $many): string
    {
        if (1 === $count) {
            return $one;
        }
        $mod10 = $count % 10;
        $mod100 = $count % 100;

        return ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14)) ? $few : $many;
    }
}
