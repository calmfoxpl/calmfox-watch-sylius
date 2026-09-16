<?php

declare(strict_types=1);

namespace Calmfox\WatchBundle\Check\Security;

use Calmfox\WatchBundle\Check\HealthCheckInterface;
use Calmfox\WatchBundle\Core\CheckResult;
use Calmfox\WatchBundle\Core\InstalledPackages;

/**
 * Pakiety deweloperskie na produkcji. Same w sobie nie wystawiają sklepu,
 * ale są martwym kodem z własnymi podatnościami, a profiler dokłada do tego
 * pełną historię żądań. Wdrożenie robi się poleceniem
 * `composer install --no-dev --optimize-autoloader`.
 */
final class DevPackagesCheck implements HealthCheckInterface
{
    private const RISKY = [
        'symfony/web-profiler-bundle',
        'symfony/maker-bundle',
        'symfony/debug-bundle',
        'phpunit/phpunit',
    ];

    public function __construct(
        private readonly string $vendorDir,
        private readonly string $environment,
    ) {
    }

    public function run(): ?CheckResult
    {
        $installed = InstalledPackages::load($this->vendorDir);
        if ([] === $installed) {
            return CheckResult::warn('dev_packages', 'Pakiety deweloperskie', 'Nie znaleziono spisu zainstalowanych pakietów (vendor/composer/installed.php), więc tego nie sprawdzamy.');
        }

        $found = array_values(array_intersect(self::RISKY, array_keys($installed)));
        if ([] === $found) {
            return CheckResult::ok('dev_packages', 'Pakiety deweloperskie', 'Brak pakietów deweloperskich w wydaniu.');
        }
        if ('prod' !== $this->environment) {
            return CheckResult::ok('dev_packages', 'Pakiety deweloperskie', sprintf('Zainstalowane pakiety deweloperskie (%d), ale aplikacja nie działa w trybie produkcyjnym.', \count($found)));
        }

        return CheckResult::warn('dev_packages', 'Pakiety deweloperskie', sprintf(
            'W wydaniu produkcyjnym są pakiety deweloperskie: %s. Wdrażaj poleceniem composer install --no-dev.',
            implode(', ', $found)
        ), fix: 'Na produkcji instaluj bez wymagań deweloperskich i po każdym wdrożeniu przeładuj autoloader. Pakiety dev zostają wtedy wyłącznie na maszynie programisty.',
            command: 'composer install --no-dev --optimize-autoloader');
    }
}
