<?php

declare(strict_types=1);

namespace Calmfox\WatchBundle\Check\Security;

use Calmfox\WatchBundle\Check\HealthCheckInterface;
use Calmfox\WatchBundle\Core\CheckResult;
use Calmfox\WatchBundle\Core\FixCommand;

/**
 * Prawa do plików z danymi dostępu. Na hostingach współdzielonych 644 to norma,
 * więc alarmujemy dopiero przy prawie ZAPISU dla grupy albo dla wszystkich:
 * kto może dopisać do .env.local, ten podmienia adres bazy i przejmuje sklep.
 */
final class ConfigPermsCheck implements HealthCheckInterface
{
    private const FILES = ['.env', '.env.local', '.env.prod.local', '.env.local.php'];

    public function __construct(private readonly string $projectDir)
    {
    }

    public function run(): ?CheckResult
    {
        $world = [];
        $group = [];
        $offenders = [];
        $checked = 0;

        foreach (self::FILES as $name) {
            $path = rtrim($this->projectDir, '/').'/'.$name;
            if (!is_file($path)) {
                continue;
            }
            ++$checked;
            $perms = fileperms($path) & 0777;
            if ($perms & 0002) {
                $world[] = sprintf('%s (%o)', $name, $perms);
                $offenders[] = $name;
            } elseif ($perms & 0020) {
                $group[] = sprintf('%s (%o)', $name, $perms);
                $offenders[] = $name;
            }
        }

        if (0 === $checked) {
            return CheckResult::ok('config_perms', 'Uprawnienia plików konfiguracji', 'Brak plików .env w katalogu projektu, ustawienia idą ze zmiennych środowiskowych.');
        }
        $fix = 'Docelowe prawa to 640: właściciel czyta i zapisuje, grupa serwera WWW tylko czyta, reszta serwera nic. Gdy PHP działa na tym samym użytkowniku co pliki, wystarczy 600.';
        if ([] !== $world) {
            return CheckResult::fail('config_perms', 'Uprawnienia plików konfiguracji', sprintf(
                'Zapisywalne dla wszystkich użytkowników serwera: %s. Ustaw 640 albo 600.',
                implode(', ', $world)
            ), fix: $fix, command: FixCommand::chmod('640', $offenders));
        }
        if ([] !== $group) {
            return CheckResult::warn('config_perms', 'Uprawnienia plików konfiguracji', sprintf(
                'Zapisywalne dla grupy: %s. Na współdzielonym hostingu warto zejść do 600.',
                implode(', ', $group)
            ), fix: $fix, command: FixCommand::chmod('640', $offenders));
        }

        return CheckResult::ok('config_perms', 'Uprawnienia plików konfiguracji', sprintf('Sprawdzone pliki (%d) nie są zapisywalne dla innych.', $checked));
    }
}
