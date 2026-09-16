<?php

declare(strict_types=1);

namespace Calmfox\WatchBundle\Tests;

use Calmfox\WatchBundle\Core\VersionSnapshot;
use PHPUnit\Framework\TestCase;

final class VersionSnapshotTest extends TestCase
{
    private const AT = '2026-08-19T06:00:00+00:00';

    public function testIdenticalSnapshotsProduceNoEntries(): void
    {
        $snapshot = ['sylius/sylius' => '1.13.2', 'php' => '8.3.14'];

        self::assertSame([], VersionSnapshot::diff($snapshot, $snapshot, self::AT));
    }

    public function testBumpAddAndRemoveAreAllDetected(): void
    {
        $before = ['sylius/sylius' => '1.13.1', 'acme/legacy-plugin' => '1.0.0', 'php' => '8.3.14'];
        $after = ['sylius/sylius' => '1.13.2', 'acme/new-plugin' => '0.9.0', 'php' => '8.3.14'];

        $entries = VersionSnapshot::diff($before, $after, self::AT);
        $byName = array_column($entries, null, 'name');

        self::assertCount(3, $entries);

        self::assertSame('1.13.1', $byName['sylius/sylius']['from']);
        self::assertSame('1.13.2', $byName['sylius/sylius']['to']);
        self::assertSame('core', $byName['sylius/sylius']['kind'], 'Pakiet platformy jedzie jako core.');

        self::assertNull($byName['acme/new-plugin']['from'], 'Nowy pakiet nie ma wersji „przed".');
        self::assertSame('0.9.0', $byName['acme/new-plugin']['to']);
        self::assertSame('plugin', $byName['acme/new-plugin']['kind']);

        self::assertSame('1.0.0', $byName['acme/legacy-plugin']['from']);
        self::assertNull($byName['acme/legacy-plugin']['to'], 'Usunięty pakiet nie ma wersji „po".');
    }

    public function testPhpChangeIsReportedAsPlatformWithReadableName(): void
    {
        $entries = VersionSnapshot::diff(['php' => '8.1.29'], ['php' => '8.2.20'], self::AT);

        self::assertCount(1, $entries);
        self::assertSame('core', $entries[0]['kind']);
        self::assertSame('PHP', $entries[0]['name']);
        self::assertSame('manual', $entries[0]['mode'], 'Composer nie mówi, kto wdrożył, więc nie zgadujemy trybu.');
        self::assertNull($entries[0]['by']);
        self::assertSame(self::AT, $entries[0]['at']);
    }

    public function testMergeKeepsNewestFirstAndCapsBufferAtTwoHundred(): void
    {
        $existing = [];
        for ($i = 0; $i < 250; ++$i) {
            $existing[] = ['name' => 'stary/'.$i];
        }
        $merged = VersionSnapshot::merge([['name' => 'nowy/pakiet']], $existing);

        self::assertCount(VersionSnapshot::MAX_ENTRIES, $merged);
        self::assertSame('nowy/pakiet', $merged[0]['name']);
    }
}
