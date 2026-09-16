<?php

declare(strict_types=1);

namespace Calmfox\WatchBundle\Core;

/**
 * Jednokierunkowy odcisk zbioru kont administracyjnych. Hub wykrywa ZMIANĘ
 * składu, nie tożsamość: loginów nie wysyłamy nigdy, bo to gotowa lista celów
 * dla ataku słownikowego. Sól z sekretu instalacji sprawia, że odcisk jest
 * bezużyteczny poza tym jednym sklepem (nie da się go porównać ze słownikiem
 * odcisków popularnych loginów).
 */
final class AdminFingerprint
{
    /** @param list<string> $identities np. „12:admin" */
    public static function of(array $identities, string $secret): string
    {
        sort($identities);

        return substr(hash_hmac('sha256', implode('|', $identities), $secret), 0, 32);
    }
}
