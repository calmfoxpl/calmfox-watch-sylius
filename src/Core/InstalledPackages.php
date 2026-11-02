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
        $data = self::read($vendorDir);
        if (null === $data || !\is_array($data['versions'] ?? null)) {
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
     * Spis komponentów do sekcji `security`, patrz {@see Inventory}.
     *
     * @return list<array{type: string, name: string, version: string}>|null
     */
    public static function inventory(string $vendorDir): ?array
    {
        $data = self::read($vendorDir);

        return null === $data ? null : Inventory::fromInstalled($data);
    }

    /** @return array<mixed>|null zawartość installed.php albo null, gdy nie ma jej jak odczytać */
    private static function read(string $vendorDir): ?array
    {
        $file = rtrim($vendorDir, '/').'/composer/installed.php';
        if (!is_file($file)) {
            return null;
        }

        /** @var mixed $data */
        $data = @include $file;

        return \is_array($data) ? $data : null;
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
