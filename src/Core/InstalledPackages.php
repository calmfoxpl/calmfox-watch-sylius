<?php

declare(strict_types=1);

namespace Calmfox\WatchBundle\Core;

/**
 * Wersje pakietów czytane z vendor/composer/installed.php. To jedyne źródło,
 * które mówi prawdę o TYM wdrożeniu (a nie o tym, co wpisano w composer.json),
 * i jest dostępne bez uruchamiania Composera.
 */
final class InstalledPackages
{
    /**
     * @return array<string, string> nazwa pakietu => wersja
     */
    public static function load(string $vendorDir): array
    {
        $file = rtrim($vendorDir, '/').'/composer/installed.php';
        if (!is_file($file)) {
            return [];
        }

        /** @var mixed $data */
        $data = @include $file;
        if (!\is_array($data) || !\is_array($data['versions'] ?? null)) {
            return [];
        }

        $out = [];
        foreach ($data['versions'] as $name => $info) {
            if (!\is_string($name) || !\is_array($info)) {
                continue;
            }
            // Pakiety „replaced"/„provided" nie mają wersji własnej instalacji.
            $version = $info['pretty_version'] ?? $info['version'] ?? null;
            if (\is_string($version) && '' !== $version) {
                $out[$name] = $version;
            }
        }
        ksort($out);

        return $out;
    }

    /**
     * Migawka do historii zmian: pakiety plus wersja PHP, bo podbicie PHP przez
     * hostingodawcę potrafi wywrócić sklep tak samo skutecznie jak aktualizacja
     * pakietu, a nigdzie indziej nie zostawia śladu.
     *
     * @param array<string, string> $packages
     *
     * @return array<string, string>
     */
    public static function withPlatform(array $packages, ?string $phpVersion = null): array
    {
        $packages['php'] = $phpVersion ?? \PHP_VERSION;
        ksort($packages);

        return $packages;
    }
}
