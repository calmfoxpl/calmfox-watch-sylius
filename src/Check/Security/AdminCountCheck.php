<?php

declare(strict_types=1);

namespace Calmfox\WatchBundle\Check\Security;

use Calmfox\WatchBundle\Check\HealthCheckInterface;
use Calmfox\WatchBundle\Core\CheckResult;
use Calmfox\WatchBundle\Signals\AdminSignals;

/** Im mniej kont z pełnym dostępem do panelu, tym mniejsza powierzchnia ataku. */
final class AdminCountCheck implements HealthCheckInterface
{
    public function __construct(private readonly AdminSignals $signals)
    {
    }

    public function run(): ?CheckResult
    {
        $snapshot = $this->signals->snapshot();
        if (!$snapshot['available']) {
            return null;
        }

        $count = $snapshot['count'];
        $many = $count > 5;

        return CheckResult::of($many ? CheckResult::WARN : CheckResult::OK, 'admin_count', 'Liczba administratorów', sprintf(
            'Włączonych kont administracyjnych: %s.%s',
            $snapshot['truncated'] ? $count.'+' : (string) $count,
            $many ? ' Im mniej kont z pełnymi uprawnieniami, tym mniejsza powierzchnia ataku. Konta osób, które odeszły, warto wyłączyć.' : ''
        ), fix: 'W panelu sklepu (Konfiguracja → Administratorzy) wyłącz konta osób, które już z nim nie pracują. Wyłączone konto zostaje w historii zamówień, usunięte z niej znika.');
    }
}
