<?php

declare(strict_types=1);

namespace Calmfox\WatchBundle\Check\Security;

use Calmfox\WatchBundle\Check\HealthCheckInterface;
use Calmfox\WatchBundle\Core\CheckResult;
use Symfony\Component\Routing\RouterInterface;

/**
 * Wyciek szczegółów działania aplikacji: wyświetlanie błędów PHP i dostępność
 * profilera. Profiler na produkcji to nie tylko wygoda dla dewelopera, ale
 * pełna historia żądań razem z nagłówkami i zapytaniami do bazy, dostępna
 * pod przewidywalnym adresem.
 */
final class DebugDisplayCheck implements HealthCheckInterface
{
    /** @param array<string, mixed> $bundles */
    public function __construct(
        private readonly array $bundles,
        private readonly ?RouterInterface $router = null,
    ) {
    }

    public function run(): ?CheckResult
    {
        $problems = [];

        $displayErrors = (string) ini_get('display_errors');
        $errorsShown = '' !== $displayErrors && !\in_array(mb_strtolower($displayErrors), ['0', 'off', ''], true);
        if ($errorsShown) {
            $problems[] = 'PHP wyświetla błędy odwiedzającym (display_errors)';
        }

        $profiler = isset($this->bundles['WebProfilerBundle']) && null !== $this->routeExists('_profiler_home');
        if ($profiler) {
            $problems[] = 'profiler jest osiągalny pod /_profiler';
        }

        $fixes = [];
        if ($errorsShown) {
            $fixes[] = 'display_errors ustaw na Off w php.ini (błędy mają iść do logu, nie na stronę).';
        }
        if ($profiler) {
            $fixes[] = 'WebProfilerBundle zostaw wyłącznie w require-dev i wdrażaj z --no-dev.';
        }

        return CheckResult::of([] !== $problems ? CheckResult::FAIL : CheckResult::OK, 'debug_display', 'Ujawnianie szczegółów błędów', [] !== $problems
            ? sprintf('%s. Każdy odwiedzający może poznać ścieżki plików, strukturę bazy i treść zapytań.', ucfirst(implode(', ', $problems)))
            : 'Błędy nie są wyświetlane odwiedzającym, profiler niedostępny.',
            fix: implode(' ', $fixes),
            // Przy błędach PHP najpierw trzeba wiedzieć, KTÓRY php.ini jest wczytany:
            // hostingi mają ich po kilka, a zmiana w niewłaściwym nic nie da.
            command: $errorsShown ? 'php --ini' : 'composer install --no-dev --optimize-autoloader');
    }

    private function routeExists(string $name): ?string
    {
        return null !== $this->router?->getRouteCollection()->get($name) ? $name : null;
    }
}
