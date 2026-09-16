<?php

declare(strict_types=1);

namespace Calmfox\WatchBundle\Core;

/**
 * Rozmiar instalacji liczony realnie, ale z twardymi limitami czasu i liczby
 * plików: adres kontrolny nie może zamulić sklepu. Po przekroczeniu limitu
 * zwracamy tyle, ile zdążyliśmy policzyć, i mówimy o tym w opisie checku.
 * Wynik i tak trafia do pamięci podręcznej na dobę.
 */
final class InstallSize
{
    public const TTL = 86400;

    /**
     * @return array{bytes: int, files: int, complete: bool}
     */
    public static function measure(string $root, float $seconds = 3.0, int $maxFiles = 200000): array
    {
        $bytes = 0;
        $files = 0;
        $complete = true;
        $deadline = microtime(true) + $seconds;

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY,
                \RecursiveIteratorIterator::CATCH_GET_CHILD
            );
            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && $file->isFile()) {
                    $bytes += (int) @$file->getSize();
                }
                ++$files;
                if ($files >= $maxFiles || (0 === $files % 2000 && microtime(true) > $deadline)) {
                    $complete = false;
                    break;
                }
            }
        } catch (\Throwable) {
            // Nieczytelny katalog nie może wywrócić checku: zwracamy, co policzone.
            $complete = false;
        }

        return ['bytes' => $bytes, 'files' => $files, 'complete' => $complete];
    }
}
