<?php

declare(strict_types=1);

namespace Calmfox\WatchBundle\Core;

/** Rozmiary po ludzku: liczby z opisu checków czyta właściciel sklepu, nie administrator serwera. */
final class Bytes
{
    public const KB = 1024;
    public const MB = 1048576;
    public const GB = 1073741824;

    public static function format(float $bytes): string
    {
        foreach ([['GB', self::GB], ['MB', self::MB], ['kB', self::KB]] as [$unit, $size]) {
            if ($bytes >= $size) {
                $value = $bytes / $size;
                $decimals = $value >= 100 ? 0 : ($value >= 10 ? 1 : 2);

                return number_format($value, $decimals, ',', ' ').' '.$unit;
            }
        }

        return number_format($bytes, 0, ',', ' ').' B';
    }
}
