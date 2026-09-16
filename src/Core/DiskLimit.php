<?php

declare(strict_types=1);

namespace Calmfox\WatchBundle\Core;

/**
 * Uczciwość odczytu dysku. Na hostingu współdzielonym disk_free_space() podaje
 * CAŁY wolumen serwera (we wtyczce WordPressa widzieliśmy „wolne 4,8 TB"
 * na koncie z kilkoma gigabajtami limitu), więc takiej liczby nie pokazujemy:
 * to nie jest informacja o koncie klienta.
 */
final class DiskLimit
{
    /** Ślady paneli hostingowych i izolacji kont. */
    public const SHARED_MARKERS = ['/usr/local/directadmin', '/usr/local/cpanel', '/opt/psa', '/proc/lve', '/var/cagefs'];

    public static function markersPresent(array $markers = self::SHARED_MARKERS): bool
    {
        foreach ($markers as $path) {
            if (@file_exists($path)) {
                return true;
            }
        }

        return false;
    }

    /** Czy odczyt systemowy dotyczy całego serwera, a nie konta klienta. */
    public static function readingIsShared(float $total, bool $markerFound, string $openBasedir = ''): bool
    {
        if ($markerFound || '' !== trim($openBasedir)) {
            return true;
        }

        // 1 TB „wolnego" to nie jest konto na hostingu współdzielonym.
        return $total >= 1024 * Bytes::GB;
    }

    /** Zajętość względem limitu podanego przez klienta. */
    public static function statusForQuota(float $used, float $limit): string
    {
        if ($limit <= 0) {
            return CheckResult::OK;
        }
        $percent = $used / $limit * 100;
        if ($percent >= 95) {
            return CheckResult::FAIL;
        }

        return $percent >= 85 ? CheckResult::WARN : CheckResult::OK;
    }

    /** Zajętość z wiarygodnego odczytu systemowego (własny serwer). */
    public static function statusForFree(float $free, float $total): string
    {
        if ($total <= 0) {
            return CheckResult::OK;
        }
        $percentFree = $free / $total * 100;
        if ($percentFree < 3 || $free < 200 * Bytes::MB) {
            return CheckResult::FAIL;
        }

        return ($percentFree < 10 || $free < Bytes::GB) ? CheckResult::WARN : CheckResult::OK;
    }

    public static function percentUsed(float $used, float $limit): int
    {
        return $limit > 0 ? (int) floor($used / $limit * 100) : 0;
    }
}
