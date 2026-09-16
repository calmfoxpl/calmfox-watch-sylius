<?php

declare(strict_types=1);

namespace Calmfox\WatchBundle\Core;

/**
 * Budowa odpowiedzi adresu kontrolnego w kształcie z WTYCZKI.md. Wydzielone
 * z kontrolera, bo to jedyne miejsce, które decyduje o kontrakcie z hubem:
 * testy trzymają je za rękę, a próbki w docs/ powstają z tego samego kodu,
 * który odpowiada monitoringowi.
 */
final class PayloadBuilder
{
    public const SCHEMA = 1;

    /**
     * @param list<array<string, mixed>>        $checks
     * @param array<string, mixed>              $site
     * @param array<string, mixed>|null         $signals
     *
     * @return array<string, mixed>
     */
    public static function health(string $pluginVersion, array $checks, array $site, ?array $signals = null): array
    {
        $payload = [
            'schema' => self::SCHEMA,
            'plugin' => $pluginVersion,
            'status' => CheckNormalizer::aggregate($checks),
            'checks' => $checks,
            'site' => $site,
        ];
        if (null !== $signals) {
            $payload['signals'] = $signals;
        }

        return $payload;
    }

    /**
     * @param list<array<string, mixed>> $checks
     * @param list<array<string, mixed>> $history
     *
     * @return array<string, mixed>
     */
    public static function security(string $pluginVersion, array $checks, array $history): array
    {
        return [
            'schema' => self::SCHEMA,
            'plugin' => $pluginVersion,
            'status' => CheckNormalizer::aggregate($checks),
            'checks' => $checks,
            'history' => $history,
        ];
    }

    /**
     * Pole `wp` nazywa się tak ze względów historycznych i niesie wersję PLATFORMY
     * (tutaj: Syliusa). `updates` POMIJAMY w całości, dopóki nikt nie policzył
     * zaległych aktualizacji: zero znaczy „sprawdzone, nie ma czego aktualizować"
     * i nie wolno go użyć jako atrapy, bo panel pokazałby zieloną wartość
     * wziętą z powietrza.
     *
     * @param array{core?: int, plugins?: int, themes?: int}|null $updates
     *
     * @return array<string, mixed>
     */
    public static function site(?string $platformVersion, string $phpVersion, string $pluginVersion, ?array $updates = null): array
    {
        $site = [
            'wp' => $platformVersion,
            'php' => $phpVersion,
            'plugin' => $pluginVersion,
        ];

        if (null !== $updates) {
            $site['updates'] = [
                'core' => max(0, (int) ($updates['core'] ?? 0)),
                'plugins' => max(0, (int) ($updates['plugins'] ?? 0)),
                // Sylius nie ma motywów w rozumieniu WordPressa, ale kontrakt ma
                // trzy liczby i hub i tak dopisałby zero.
                'themes' => max(0, (int) ($updates['themes'] ?? 0)),
            ];
        }

        return $site;
    }
}
