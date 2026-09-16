<?php

declare(strict_types=1);

namespace Calmfox\WatchBundle\Tests;

use Calmfox\WatchBundle\Core\CheckNormalizer;
use Calmfox\WatchBundle\Core\CheckResult;
use Calmfox\WatchBundle\Core\PayloadBuilder;
use PHPUnit\Framework\TestCase;

final class PayloadBuilderTest extends TestCase
{
    public function testUpdatesFieldIsOmittedWhenNobodyCountedThem(): void
    {
        $site = PayloadBuilder::site('1.13.2', '8.3.14', '1.0.0', null);

        self::assertArrayNotHasKey('updates', $site, 'Zero znaczy „sprawdzone", więc przy braku danych pomijamy całe pole.');
        self::assertSame(['wp' => '1.13.2', 'php' => '8.3.14', 'plugin' => '1.0.0'], $site);
    }

    public function testUpdatesFieldCarriesAllThreeCountersWhenKnown(): void
    {
        $site = PayloadBuilder::site('1.13.2', '8.3.14', '1.0.0', ['core' => 1, 'plugins' => 7]);

        self::assertSame(['core' => 1, 'plugins' => 7, 'themes' => 0], $site['updates']);
    }

    public function testHealthPayloadKeepsContractShapeAndAggregatesStatus(): void
    {
        $checks = CheckNormalizer::normalize([
            CheckResult::ok('db', 'Baza danych', null, 3),
            CheckResult::warn('disk', 'Miejsce na dysku', 'Zajęte 87%.'),
        ]);

        $payload = PayloadBuilder::health('1.0.0', $checks, PayloadBuilder::site('1.13.2', '8.3.14', '1.0.0'), [
            'adminCount' => 3,
            'adminsFingerprint' => str_repeat('a', 32),
            'newestAdminAt' => '2026-07-30T09:12:00+00:00',
        ]);

        self::assertSame(1, $payload['schema']);
        self::assertSame('warn', $payload['status']);
        self::assertSame(['schema', 'plugin', 'status', 'checks', 'site', 'signals'], array_keys($payload));
    }

    public function testSecurityPayloadCarriesHistoryAndNoSiteInfo(): void
    {
        $payload = PayloadBuilder::security('1.0.0', CheckNormalizer::normalize([CheckResult::fail('https', 'HTTPS', 'Brak szyfrowania.')]), []);

        self::assertSame('fail', $payload['status']);
        self::assertSame(['schema', 'plugin', 'status', 'checks', 'history'], array_keys($payload));
    }

    /**
     * Pole `site.updates` zostaje WYŁĄCZNIE liczbami: lista pakietów z wersjami
     * to gotowa mapa dziur dla atakującego. Nazwy jadą tylko tam, gdzie są istotą
     * funkcji: w historii zmian i w składzie włączonych bundli (signals), gdzie
     * bez nazwy zdarzenie mówiłoby klientowi tylko „coś się zmieniło".
     */
    public function testUpdatesCountersNeverCarryPackageNames(): void
    {
        $payload = PayloadBuilder::health('1.0.0', [], PayloadBuilder::site('1.13.2', '8.3.14', '1.0.0', ['core' => 1, 'plugins' => 7]));

        self::assertStringNotContainsString('sylius/', json_encode($payload, \JSON_THROW_ON_ERROR));
        self::assertArrayNotHasKey('history', $payload);
    }
}
