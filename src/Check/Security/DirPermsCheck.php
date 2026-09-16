<?php

declare(strict_types=1);

namespace Calmfox\WatchBundle\Check\Security;

use Calmfox\WatchBundle\Check\HealthCheckInterface;
use Calmfox\WatchBundle\Core\CheckResult;
use Calmfox\WatchBundle\Core\FixCommand;

/**
 * Katalogi zapisywalne dla wszystkich. 777 na katalogu mediów to najkrótsza
 * droga od cudzego procesu na serwerze do własnego pliku PHP w sklepie.
 */
final class DirPermsCheck implements HealthCheckInterface
{
    public function __construct(
        private readonly string $varDir,
        private readonly string $mediaDir,
    ) {
    }

    public function run(): ?CheckResult
    {
        $world = [];
        $paths = [];
        foreach (['var/' => $this->varDir, 'public/media' => $this->mediaDir] as $name => $dir) {
            if (is_dir($dir) && (fileperms($dir) & 0002)) {
                $world[] = sprintf('%s (%o)', $name, fileperms($dir) & 0777);
                $paths[] = rtrim($name, '/');
            }
        }

        // -R świadomie: prawa 777 zwykle siedzą też w podkatalogach, a zmiana samego
        // katalogu nadrzędnego zostawiłaby otwarte dokładnie te miejsca, do których
        // trafiają wgrywane pliki.
        return CheckResult::of([] !== $world ? CheckResult::FAIL : CheckResult::OK, 'dir_perms', 'Uprawnienia katalogów', [] !== $world
            ? sprintf('Zapisywalne dla wszystkich: %s. Dowolny proces na serwerze może umieścić tam własny plik.', implode(', ', $world))
            : 'Katalogi zapisywalne wyłącznie dla właściciela.',
            fix: 'Katalogom roboczym wystarczy 755, a gdy serwer WWW pracuje na innym użytkowniku niż właściciel plików, 775 przy wspólnej grupie.',
            command: FixCommand::chmod('755', $paths, recursive: true));
    }
}
