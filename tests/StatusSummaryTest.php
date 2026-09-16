<?php

declare(strict_types=1);

namespace Calmfox\WatchBundle\Tests;

use Calmfox\WatchBundle\Core\CheckNormalizer;
use Calmfox\WatchBundle\Core\CheckResult;
use Calmfox\WatchBundle\Core\PayloadBuilder;
use Calmfox\WatchBundle\Core\StatusSummary;
use PHPUnit\Framework\TestCase;

final class StatusSummaryTest extends TestCase
{
    /** @param list<CheckResult> $checks */
    private static function health(array $checks): array
    {
        return PayloadBuilder::health('1.2.0', CheckNormalizer::normalize($checks), PayloadBuilder::site('2.0.1', '8.3.14', '1.2.0'));
    }

    /** @param list<CheckResult> $checks */
    private static function security(array $checks): array
    {
        return PayloadBuilder::security('1.2.0', CheckNormalizer::normalize($checks), []);
    }

    public function testFailuresGoBeforeWarningsRegardlessOfSection(): void
    {
        $summary = StatusSummary::of(
            self::health([
                CheckResult::ok('db', 'Baza danych'),
                CheckResult::warn('disk', 'Miejsce na dysku', 'Zajęte 87%.'),
            ]),
            self::security([
                CheckResult::fail('https', 'Szyfrowanie HTTPS', 'Sklep odpowiada po http.'),
                CheckResult::warn('php_version', 'Wersja PHP', 'Tylko poprawki bezpieczeństwa.'),
            ])
        );

        self::assertSame('fail', $summary['status']);
        self::assertSame(['https', 'disk', 'php_version'], array_column($summary['problems'], 'id'));
        self::assertSame(['ok' => 1, 'warn' => 2, 'fail' => 1], $summary['counts']);
        self::assertSame(3, $summary['total']);
    }

    public function testHealthyStoreHasNothingToShow(): void
    {
        $summary = StatusSummary::of(self::health([CheckResult::ok('db', 'Baza danych')]), self::security([CheckResult::ok('https', 'HTTPS')]));

        self::assertSame('ok', $summary['status']);
        self::assertSame([], $summary['problems']);
        self::assertSame(0, $summary['total']);
    }

    /** Kafelek pokazuje trzy pozycje, ale ma wiedzieć, ile ich jest naprawdę. */
    public function testListIsCappedWhileTheCounterStaysHonest(): void
    {
        $checks = [];
        for ($i = 1; $i <= 6; ++$i) {
            $checks[] = CheckResult::warn('check_'.$i, 'Sprawdzenie '.$i, 'Coś do poprawy.');
        }

        $summary = StatusSummary::of(self::health($checks), []);

        self::assertCount(3, $summary['problems']);
        self::assertSame(6, $summary['total']);
        self::assertSame('warn', $summary['status']);
    }

    /** Sekcja, której nie udało się policzyć, nie może wywrócić kafelka. */
    public function testMissingSectionsAreTreatedAsNoChecks(): void
    {
        $summary = StatusSummary::of([], []);

        self::assertSame('ok', $summary['status']);
        self::assertSame(['ok' => 0, 'warn' => 0, 'fail' => 0], $summary['counts']);
    }
}
