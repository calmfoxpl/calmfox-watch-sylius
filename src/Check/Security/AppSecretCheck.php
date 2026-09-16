<?php

declare(strict_types=1);

namespace Calmfox\WatchBundle\Check\Security;

use Calmfox\WatchBundle\Check\HealthCheckInterface;
use Calmfox\WatchBundle\Core\CheckResult;

/**
 * APP_SECRET. Z niego liczą się podpisy ciasteczek „pamiętaj mnie" i tokeny
 * formularzy, więc wartość domyślna albo krótka znaczy, że sesję administratora
 * da się podrobić bez znajomości hasła.
 */
final class AppSecretCheck implements HealthCheckInterface
{
    /** Wartości z gotowych szkieletów projektu i z dokumentacji. */
    private const KNOWN_DEFAULTS = [
        'thistokenisnotsosecretchangeit',
        '!changeme!',
        'changeme',
        'editme',
        'secret',
        'app_secret',
    ];

    private const MIN_LENGTH = 32;

    public function __construct(private readonly string $secret)
    {
    }

    public function run(): ?CheckResult
    {
        $value = trim($this->secret);
        $label = 'Klucz aplikacji (APP_SECRET)';

        // Skutek uboczny wymieniamy w podpowiedzi, bo jest pewny i widoczny:
        // po podmianie klucza wszyscy zalogowani wylatują z sesji.
        $fix = 'Wygeneruj losowy klucz i wpisz go do APP_SECRET w .env.local, a potem wyczyść pamięć podręczną. Zalogowani zostaną wylogowani: to normalne, nie awaria.';
        $command = 'php -r "echo bin2hex(random_bytes(16)), PHP_EOL;"';

        if ('' === $value) {
            return CheckResult::fail('app_secret', $label, 'Klucz aplikacji jest pusty. Podpisy ciasteczek i tokeny formularzy przestają cokolwiek chronić.', fix: $fix, command: $command);
        }
        if (\in_array(mb_strtolower($value), self::KNOWN_DEFAULTS, true)) {
            return CheckResult::fail('app_secret', $label, 'Klucz aplikacji ma wartość domyślną ze szkieletu projektu. Każdy zna ją z dokumentacji i może podrobić sesję administratora. Wygeneruj nowy.', fix: $fix, command: $command);
        }
        if (mb_strlen($value) < self::MIN_LENGTH) {
            return CheckResult::warn('app_secret', $label, sprintf(
                'Klucz aplikacji ma %d znaków, zalecane minimum to %d. Wygeneruj dłuższy, na przykład 32 losowe znaki szesnastkowe.',
                mb_strlen($value),
                self::MIN_LENGTH
            ), fix: $fix, command: $command);
        }

        return CheckResult::ok('app_secret', $label, 'Klucz aplikacji jest własny i odpowiednio długi.');
    }
}
