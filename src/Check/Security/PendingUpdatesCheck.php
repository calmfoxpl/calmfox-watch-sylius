<?php

declare(strict_types=1);

namespace Calmfox\WatchBundle\Check\Security;

use Calmfox\WatchBundle\Check\HealthCheckInterface;
use Calmfox\WatchBundle\Core\CheckResult;
use Calmfox\WatchBundle\Core\StateStore;

/**
 * Zaległe aktualizacje. Composer nie odpowie na to pytanie w trakcie żądania
 * HTTP (potrzebuje sieci i sporo czasu), więc liczby bierzemy z pamięci stanu
 * zapisanej przez polecenie calmfox:watch:updates w cronie.
 *
 * Gdy nikt nigdy nie policzył, mówimy o tym wprost i pole `updates` w payloadzie
 * POMIJAMY. Zero znaczyłoby „sprawdzone, nie ma czego aktualizować", a to
 * nieprawda i panel pokazałby zielony wynik wzięty z powietrza.
 */
final class PendingUpdatesCheck implements HealthCheckInterface
{
    public const STATE_KEY = 'updates';

    /** Po tylu dniach bez przeliczenia liczby przestają być wiarygodne. */
    private const STALE_AFTER_DAYS = 14;

    public function __construct(private readonly StateStore $state)
    {
    }

    public function run(): ?CheckResult
    {
        $label = 'Zaległe aktualizacje';
        $stored = $this->state->get(self::STATE_KEY);
        if (!\is_array($stored) || !isset($stored['plugins'])) {
            return CheckResult::warn('pending_updates', $label,
                'Nie sprawdzamy zaległych aktualizacji: nikt jeszcze nie uruchomił polecenia calmfox:watch:updates. Dopisz je do crona (raz na dobę wystarczy), a zaczniemy je liczyć.',
                fix: 'Uruchom polecenie raz ręcznie, a potem dopisz je do crona raz na dobę (wpis: 0 4 * * * cd /sciezka/do/sklepu && bin/console calmfox:watch:updates).',
                command: 'bin/console calmfox:watch:updates');
        }

        $core = (int) ($stored['core'] ?? 0);
        $plugins = (int) ($stored['plugins'] ?? 0);
        $at = \is_string($stored['at'] ?? null) ? $stored['at'] : null;

        if (null !== $at && strtotime($at) < time() - self::STALE_AFTER_DAYS * 86400) {
            return CheckResult::warn('pending_updates', $label, sprintf(
                'Ostatnie przeliczenie %s. Liczby są nieaktualne, sprawdź, czy polecenie calmfox:watch:updates dalej chodzi w cronie.',
                substr($at, 0, 10)
            ), fix: 'Sprawdź wpis w cronie i uruchom polecenie ręcznie: jeśli przejdzie z konsoli, problem jest w harmonogramie, a nie w samym poleceniu.',
                command: 'bin/console calmfox:watch:updates');
        }

        if ($core > 0) {
            return CheckResult::warn('pending_updates', $label, 'Dostępna aktualizacja Syliusa. Aktualizacja platformy jest najpilniejsza, bo to ona dostaje poprawki bezpieczeństwa.',
                fix: 'Zacznij od sprawdzenia, co ma nowsze wersje, i aktualizuj najpierw platformę, na środowisku testowym, nie od razu na sklepie produkcyjnym.',
                command: 'composer outdated --direct');
        }

        return CheckResult::of($plugins >= 10 ? CheckResult::WARN : CheckResult::OK, 'pending_updates', $label, sprintf(
            'Pakietów do aktualizacji: %d.%s',
            $plugins,
            $plugins >= 10 ? ' Im dłużej odkładana aktualizacja, tym trudniejsza i ryzykowniejsza.' : ''
        ), fix: 'Przejrzyj listę zaległych pakietów i zaplanuj aktualizację partiami, zaczynając od tych, które dotykają płatności i bezpieczeństwa.',
            command: 'composer outdated --direct');
    }
}
