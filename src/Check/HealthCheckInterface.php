<?php

declare(strict_types=1);

namespace Calmfox\WatchBundle\Check;

use Calmfox\WatchBundle\Core\CheckResult;

/**
 * Punkt rozszerzenia dla usług, o których wie tylko właściciel sklepu:
 * własny broker kolejek, integracja z magazynem, demon synchronizacji cen.
 * Usługa implementująca ten interfejs dopina się do sekcji `health` sama
 * (tag `calmfox_watch.health_check`), a wynik przechodzi przez tę samą
 * normalizację co checki wbudowane.
 *
 * Dwie zasady, obie wynikają z kontraktu:
 * 1. Zwróć `null`, gdy check nie dotyczy tej instalacji. Nie wysyłamy „ok"
 *    o czymś, czego nie ma.
 * 2. Trzymaj się krótkiego, twardego limitu czasu. Adres kontrolny odpowiada
 *    monitoringowi co minutę i nie może zamulić sklepu.
 */
interface HealthCheckInterface
{
    public function run(): ?CheckResult;
}
