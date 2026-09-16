<?php

declare(strict_types=1);

namespace Calmfox\WatchBundle\Tests;

use Calmfox\WatchBundle\Core\Bytes;
use Calmfox\WatchBundle\Core\DiskLimit;
use PHPUnit\Framework\TestCase;

final class DiskLimitTest extends TestCase
{
    public function testHugeVolumeOrHostingMarkersMeanTheReadingIsNotAboutThisAccount(): void
    {
        self::assertTrue(DiskLimit::readingIsShared(4.8 * 1024 * Bytes::GB, false, ''), 'Wolne 4,8 TB to nie jest konto klienta.');
        self::assertTrue(DiskLimit::readingIsShared(50 * Bytes::GB, true, ''), 'Panel hostingowy na serwerze znaczy konto współdzielone.');
        self::assertTrue(DiskLimit::readingIsShared(50 * Bytes::GB, false, '/home/klient:/tmp'), 'open_basedir znaczy izolację konta.');
        self::assertFalse(DiskLimit::readingIsShared(200 * Bytes::GB, false, ''), 'Własny serwer: odczyt systemowy jest wiarygodny.');
    }

    public function testQuotaThresholds(): void
    {
        $limit = 20.0 * Bytes::GB;

        self::assertSame('ok', DiskLimit::statusForQuota(10 * Bytes::GB, $limit));
        self::assertSame('warn', DiskLimit::statusForQuota(17.4 * Bytes::GB, $limit));
        self::assertSame('fail', DiskLimit::statusForQuota(19.5 * Bytes::GB, $limit));
        self::assertSame('ok', DiskLimit::statusForQuota(19.5 * Bytes::GB, 0.0), 'Bez limitu nie ma czego przekroczyć.');
    }

    public function testFreeSpaceThresholds(): void
    {
        self::assertSame('ok', DiskLimit::statusForFree(50 * Bytes::GB, 200 * Bytes::GB));
        self::assertSame('warn', DiskLimit::statusForFree(15 * Bytes::GB, 200 * Bytes::GB));
        self::assertSame('fail', DiskLimit::statusForFree(150 * Bytes::MB, 200 * Bytes::GB));
    }

    public function testPercentUsed(): void
    {
        self::assertSame(86, DiskLimit::percentUsed(17.4 * Bytes::GB, 20.0 * Bytes::GB), "Zaokrąglamy w dół: wolimy powiedzieć mniej, niż zawyżyć zajętość.");
        self::assertSame(0, DiskLimit::percentUsed(5.0, 0.0));
    }

    public function testBytesAreFormattedForPeopleNotForServers(): void
    {
        self::assertSame('1,50 GB', Bytes::format(1.5 * Bytes::GB));
        self::assertSame('512 MB', Bytes::format(512 * Bytes::MB));
        self::assertSame('999 B', Bytes::format(999));
    }
}
