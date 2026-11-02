<?php

declare(strict_types=1);

namespace Calmfox\WatchBundle\Tests;

use Calmfox\WatchBundle\Core\InstalledPackages;
use Calmfox\WatchBundle\Core\Inventory;
use PHPUnit\Framework\TestCase;

final class InventoryTest extends TestCase
{
    public function testListsPackagesWithPrettyVersionsSorted(): void
    {
        $inventory = Inventory::fromInstalled(['versions' => [
            'symfony/yaml' => ['pretty_version' => 'v7.4.15', 'version' => '7.4.15.0'],
            'acme/lib' => ['pretty_version' => '2.1.3', 'version' => '2.1.3.0'],
        ]]);

        self::assertSame([
            ['type' => 'package', 'name' => 'acme/lib', 'version' => '2.1.3'],
            ['type' => 'package', 'name' => 'symfony/yaml', 'version' => 'v7.4.15'],
        ], $inventory);
    }

    public function testSkipsRootPackageAndPackagesWithoutOwnInstallation(): void
    {
        $inventory = Inventory::fromInstalled([
            'root' => ['name' => 'klient/sklep'],
            'versions' => [
                'klient/sklep' => ['pretty_version' => 'dev-main', 'version' => 'dev-main'],
                '__root__' => ['pretty_version' => '1.0.0', 'version' => '1.0.0.0'],
                'psr/log-implementation' => ['provided' => ['1.0|2.0|3.0']],
                'symfony/polyfill-php80' => ['replaced' => ['*']],
                'acme/lib' => ['pretty_version' => '2.1.3', 'version' => '2.1.3.0'],
            ],
        ]);

        self::assertSame([['type' => 'package', 'name' => 'acme/lib', 'version' => '2.1.3']], $inventory);
    }

    public function testNoDataMeansNoInventoryRatherThanAnEmptyOne(): void
    {
        self::assertNull(Inventory::fromInstalled([]), 'Pusty spis znaczyłby „sprawdzone, nic nie ma".');
        self::assertNull(Inventory::fromInstalled(['versions' => []]));
    }

    public function testInventoryOverTheHubCeilingIsNotSent(): void
    {
        $versions = [];
        for ($i = 0; $i <= Inventory::MAX_ENTRIES; ++$i) {
            $versions['v/p'.$i] = ['pretty_version' => '1.0.0'];
        }

        self::assertNull(Inventory::fromInstalled(['versions' => $versions]));
    }

    /** Odpowiedź ponad limit huba wyglądałaby jak milczący pakiet i otworzyłaby alarm. */
    public function testInventoryOverTheByteBudgetIsNotSent(): void
    {
        $versions = [];
        for ($i = 0; $i < Inventory::MAX_ENTRIES; ++$i) {
            $versions[sprintf('vendor-o-bardzo-dlugiej-nazwie/paczka-o-bardzo-dlugiej-nazwie-%04d', $i)] = ['pretty_version' => '1.0.0'];
        }

        self::assertNull(Inventory::fromInstalled(['versions' => $versions]));
    }

    public function testInventoryIsReadFromInstalledPhp(): void
    {
        $vendor = sys_get_temp_dir().'/calmfox-inventory-'.bin2hex(random_bytes(4));
        mkdir($vendor.'/composer', 0777, true);
        file_put_contents($vendor.'/composer/installed.php', "<?php return ['root' => ['name' => 'klient/sklep'], 'versions' => ['klient/sklep' => ['pretty_version' => 'dev-main'], 'acme/lib' => ['pretty_version' => '2.1.3']]];");

        try {
            self::assertSame([['type' => 'package', 'name' => 'acme/lib', 'version' => '2.1.3']], InstalledPackages::inventory($vendor));
        } finally {
            unlink($vendor.'/composer/installed.php');
            rmdir($vendor.'/composer');
            rmdir($vendor);
        }
    }
}
