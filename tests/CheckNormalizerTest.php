<?php

declare(strict_types=1);

namespace Calmfox\WatchBundle\Tests;

use Calmfox\WatchBundle\Core\CheckNormalizer;
use Calmfox\WatchBundle\Core\CheckResult;
use PHPUnit\Framework\TestCase;

final class CheckNormalizerTest extends TestCase
{
    public function testAggregateFailBeatsWarnAndWarnBeatsOk(): void
    {
        self::assertSame('ok', CheckNormalizer::aggregate([]));
        self::assertSame('ok', CheckNormalizer::aggregate([CheckResult::ok('db', 'Baza')]));
        self::assertSame('warn', CheckNormalizer::aggregate([
            CheckResult::ok('db', 'Baza'),
            CheckResult::warn('disk', 'Dysk'),
        ]));
        self::assertSame('fail', CheckNormalizer::aggregate([
            CheckResult::ok('db', 'Baza'),
            CheckResult::warn('disk', 'Dysk'),
            CheckResult::fail('smtp', 'Poczta'),
        ]));
        // Kolejność nie ma znaczenia: fail wygrywa nawet na końcu listy.
        self::assertSame('fail', CheckNormalizer::aggregate([
            CheckResult::fail('smtp', 'Poczta'),
            CheckResult::ok('db', 'Baza'),
        ]));
    }

    public function testNormalizeDropsChecksOutsideContract(): void
    {
        $normalized = CheckNormalizer::normalize([
            CheckResult::ok('db', 'Baza danych'),
            ['id' => 'ŹLE', 'status' => 'ok', 'label' => 'Zły identyfikator'],
            ['id' => 'unknown_status', 'status' => 'critical', 'label' => 'Zły status'],
            ['id' => 'ok_array', 'status' => 'warn', 'label' => 'Tablica też przechodzi'],
            'nonsens',
            null,
        ]);

        self::assertSame(['db', 'ok_array'], array_column($normalized, 'id'));
    }

    public function testNormalizeTrimsTextsAndRejectsNegativeTiming(): void
    {
        $normalized = CheckNormalizer::normalize([
            ['id' => 'DB', 'status' => 'ok', 'label' => str_repeat('a', 200), 'detail' => "  wiele\n\n spacji  <b>i</b> znaczników ", 'ms' => -5],
        ]);

        self::assertCount(1, $normalized);
        self::assertSame('db', $normalized[0]['id'], 'Identyfikator zjeżdża do małych liter jak w hubie.');
        self::assertSame(80, mb_strlen((string) $normalized[0]['label']));
        self::assertSame('wiele spacji i znaczników', $normalized[0]['detail']);
        self::assertNull($normalized[0]['ms']);
    }

    public function testNormalizeStopsAtSixtyChecks(): void
    {
        $many = [];
        for ($i = 0; $i < 80; ++$i) {
            $many[] = CheckResult::ok('check_'.$i, 'Check '.$i);
        }

        self::assertCount(60, CheckNormalizer::normalize($many));
    }

    /**
     * Podpowiedź naprawcza jedzie razem z checkiem, a polecenie tylko wtedy, gdy
     * da się je bezpiecznie pokazać do skopiowania: jedna linia, drukowalne ASCII
     * i mieszczące się w limicie. Hub przycina tak samo, więc ekran w panelu sklepu
     * i panel Calmfox pokazują dokładnie to samo.
     */
    public function testFixAndCommandFollowTheContract(): void
    {
        $checks = CheckNormalizer::normalize([
            CheckResult::warn('config_perms', 'Uprawnienia', null, null, str_repeat('x', 400), 'chmod 640 .env.local'),
            CheckResult::fail('dir_perms', 'Uprawnienia katalogów', null, null, 'Ustaw 755.', 'chmod -R 755 '.str_repeat('a', 200)),
            CheckResult::fail('https', 'HTTPS', null, null, 'Włącz certyfikat.', 'echo „cudzysłów drukarski”'),
        ]);

        self::assertSame(200, mb_strlen((string) $checks[0]['fix']));
        self::assertSame('chmod 640 .env.local', $checks[0]['command']);
        self::assertNull($checks[1]['command'], 'za długie polecenie wypada w całości, nie przycinamy go w połowie ścieżki');
        self::assertNull($checks[2]['command'], 'poza drukowalnym ASCII nie przepuszczamy nic');
    }

    /** Przy działającym parametrze nie ma czego naprawiać, więc podpowiedź nie wychodzi na łącze. */
    public function testOkCheckCarriesNoFix(): void
    {
        $checks = CheckNormalizer::normalize([
            CheckResult::of(CheckResult::OK, 'config_perms', 'Uprawnienia', null, null, 'Ustaw 640.', 'chmod 640 .env.local'),
        ]);

        self::assertNull($checks[0]['fix']);
        self::assertNull($checks[0]['command']);
    }
}
