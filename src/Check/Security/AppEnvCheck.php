<?php

declare(strict_types=1);

namespace Calmfox\WatchBundle\Check\Security;

use Calmfox\WatchBundle\Check\HealthCheckInterface;
use Calmfox\WatchBundle\Core\CheckResult;

/**
 * Tryb pracy aplikacji. Sklep w trybie deweloperskim na produkcji to nie jest
 * drobiazg: pokazuje pełne komunikaty błędów ze ścieżkami i zapytaniami,
 * wystawia pasek narzędziowy i jest kilka razy wolniejszy.
 */
final class AppEnvCheck implements HealthCheckInterface
{
    public function __construct(
        private readonly string $environment,
        private readonly bool $debug,
    ) {
    }

    public function run(): ?CheckResult
    {
        $problems = [];
        if ('prod' !== $this->environment) {
            $problems[] = sprintf('APP_ENV=%s zamiast prod', $this->environment);
        }
        if ($this->debug) {
            $problems[] = 'APP_DEBUG włączone';
        }

        return CheckResult::of([] !== $problems ? CheckResult::FAIL : CheckResult::OK, 'app_env', 'Tryb pracy aplikacji', [] !== $problems
            ? sprintf('%s. Sklep produkcyjny ujawnia wtedy szczegóły błędów i działa wolniej.', implode(', ', $problems))
            : 'Tryb produkcyjny, bez trybu diagnostycznego.',
            fix: 'W .env.local ustaw APP_ENV=prod i APP_DEBUG=0, a po zmianie przebuduj pamięć podręczną. Bez tego Symfony przelicza konfigurację przy każdym żądaniu.',
            command: 'bin/console cache:clear --env=prod --no-debug');
    }
}
