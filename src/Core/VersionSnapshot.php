<?php

declare(strict_types=1);

namespace Calmfox\WatchBundle\Core;

/**
 * Historia zmian wersji bez haka aktualizacji. Sylius nie ma odpowiednika
 * `upgrader_process_complete` z WordPressa (wdrożenie robi Composer, często
 * z zupełnie innej maszyny), więc porównujemy MIGAWKI: co się zmieniło między
 * poprzednim a bieżącym odczytem vendor/composer/installed.php.
 *
 * Konsekwencja, którą trzeba nazwać wprost: `at` to czas WYKRYCIA zmiany,
 * a nie czas wdrożenia. Zwykle różnią się o minuty (pierwsze odpytanie po
 * wdrożeniu), ale przy sklepie bez ruchu może to być więcej. Do zdania
 * „awaria zaczęła się po aktualizacji pakietu X" to wystarcza, do rozliczania
 * wdrożeń co do sekundy nie.
 */
final class VersionSnapshot
{
    /** Pakiety platformy: ich zmiana to `core`, reszta to `plugin`. Motywów tu nie ma. */
    public const PLATFORM_PACKAGES = ['sylius/sylius', 'php'];

    public const MAX_ENTRIES = 200;

    /**
     * @param array<string, string> $before
     * @param array<string, string> $after
     * @param list<string>          $platformPackages
     *
     * @return list<array{kind: string, name: string, from: ?string, to: ?string, at: string, mode: string, by: null}>
     */
    public static function diff(array $before, array $after, string $at, array $platformPackages = self::PLATFORM_PACKAGES): array
    {
        $entries = [];

        foreach ($after as $name => $version) {
            $previous = $before[$name] ?? null;
            if ($previous === $version) {
                continue;
            }
            // Nowy pakiet: `from` zostaje puste, bo wcześniej go nie było.
            $entries[] = self::entry($name, \is_string($previous) ? $previous : null, (string) $version, $at, $platformPackages);
        }

        foreach ($before as $name => $version) {
            if (!\array_key_exists($name, $after)) {
                // Usunięcie pakietu też bywa przyczyną awarii („zniknęła integracja płatności").
                $entries[] = self::entry($name, (string) $version, null, $at, $platformPackages);
            }
        }

        usort($entries, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $entries;
    }

    /**
     * @param list<array<string, mixed>> $fresh
     * @param list<array<string, mixed>> $existing
     *
     * @return list<array<string, mixed>>
     */
    public static function merge(array $fresh, array $existing): array
    {
        return \array_slice(array_merge($fresh, $existing), 0, self::MAX_ENTRIES);
    }

    /**
     * @param list<string> $platformPackages
     *
     * @return array{kind: string, name: string, from: ?string, to: ?string, at: string, mode: string, by: null}
     */
    private static function entry(string $name, ?string $from, ?string $to, string $at, array $platformPackages): array
    {
        return [
            'kind' => \in_array($name, $platformPackages, true) ? 'core' : 'plugin',
            'name' => 'php' === $name ? 'PHP' : mb_substr($name, 0, 120),
            'from' => null !== $from ? mb_substr($from, 0, 32) : null,
            'to' => null !== $to ? mb_substr($to, 0, 32) : null,
            'at' => $at,
            // Composer nie zostawia śladu, kto i skąd wdrożył, więc nie zgadujemy:
            // `manual` i brak autora są uczciwsze niż wymyślony wpis.
            'mode' => 'manual',
            'by' => null,
        ];
    }
}
