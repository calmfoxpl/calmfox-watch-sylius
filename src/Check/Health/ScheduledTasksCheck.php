<?php

declare(strict_types=1);

namespace Calmfox\WatchBundle\Check\Health;

use Calmfox\WatchBundle\Check\HealthCheckInterface;
use Calmfox\WatchBundle\Core\CheckResult;
use Calmfox\WatchBundle\Core\StateStore;

/**
 * Czy zadania cykliczne sklepu faktycznie chodzą. Symfony nie ma odpowiednika
 * WP-Crona (nie ma czego podejrzeć), więc sprawdzamy to jedynym uczciwym
 * sposobem: pakiet wystawia polecenie „bicia serca", cron je wywołuje, a check
 * pilnuje świeżości znacznika. Jeżeli cron przestanie działać, przestaną też
 * chodzić sylius:cancel-unpaid-orders i sylius:remove-expired-carts, czyli
 * porzucone koszyki będą blokować stany magazynowe.
 *
 * Gdy znacznika nigdy nie było, mówimy `warn` i wprost: „nie wiemy, dopisz cron".
 * Zielone „ok" znaczyłoby, że coś sprawdziliśmy, a nie sprawdziliśmy nic.
 */
final class ScheduledTasksCheck implements HealthCheckInterface
{
    public const STATE_KEY = 'heartbeatAt';

    private const LABEL = 'Zadania cykliczne';

    public function __construct(
        private readonly StateStore $state,
        private readonly int $interval,
    ) {
    }

    public function run(): ?CheckResult
    {
        $last = (int) $this->state->get(self::STATE_KEY, 0);
        if ($last <= 0) {
            return CheckResult::warn('scheduled_tasks', self::LABEL,
                'Nie wiemy, czy zadania cykliczne działają: nigdy nie doszło bicie serca. Dopisz do crona polecenie calmfox:watch:heartbeat, wtedy zaczniemy pilnować także pozostałych zadań sklepu.');
        }

        $age = max(0, time() - $last);
        // Trzy pominięte wywołania to jeszcze zbieg okoliczności, sześć to awaria.
        $status = CheckResult::OK;
        if ($age > 6 * $this->interval) {
            $status = CheckResult::FAIL;
        } elseif ($age > 3 * $this->interval) {
            $status = CheckResult::WARN;
        }

        if (CheckResult::OK === $status) {
            return CheckResult::ok('scheduled_tasks', self::LABEL, sprintf('Ostatnie wywołanie %s temu.', self::duration($age)));
        }

        return CheckResult::of($status, 'scheduled_tasks', self::LABEL, sprintf(
            'Ostatnie wywołanie %s temu, a spodziewamy się go co %s. Cron prawdopodobnie przestał działać: porzucone koszyki i nieopłacone zamówienia nie będą sprzątane.',
            self::duration($age),
            self::duration($this->interval)
        ));
    }

    private static function duration(int $seconds): string
    {
        if ($seconds < 120) {
            return sprintf('%d s', $seconds);
        }
        if ($seconds < 7200) {
            return sprintf('%d min', (int) round($seconds / 60));
        }
        if ($seconds < 172800) {
            return sprintf('%d godz.', (int) round($seconds / 3600));
        }

        return sprintf('%d dni', (int) round($seconds / 86400));
    }
}
