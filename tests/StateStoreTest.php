<?php

declare(strict_types=1);

namespace Calmfox\WatchBundle\Tests;

use Calmfox\WatchBundle\Core\StateStore;
use PHPUnit\Framework\TestCase;

final class StateStoreTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/calmfox-watch-state-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/{,.}*', \GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        @rmdir($this->dir);
    }

    public function testMissingFileReadsAsEmptyStateInsteadOfThrowing(): void
    {
        $store = new StateStore($this->dir.'/nie-ma-takiego');

        self::assertSame([], $store->all());
        self::assertNull($store->get('secret'));
        self::assertSame('domyślna', $store->get('secret', 'domyślna'));
    }

    public function testValuesSurviveBetweenInstancesAndMergeInsteadOfOverwriting(): void
    {
        (new StateStore($this->dir))->set(['secret' => 'abc', 'connected' => true]);
        (new StateStore($this->dir))->set(['plan' => 'start']);

        $store = new StateStore($this->dir);
        self::assertSame('abc', $store->get('secret'));
        self::assertTrue($store->get('connected'));
        self::assertSame('start', $store->get('plan'));
    }

    public function testRemoveDropsOnlyTheGivenKeys(): void
    {
        $store = new StateStore($this->dir);
        $store->set(['secret' => 'abc', 'pairingNonce' => 'xyz']);
        $store->remove('pairingNonce');

        self::assertSame('abc', $store->get('secret'));
        self::assertNull($store->get('pairingNonce'));
    }

    public function testDirectoryIsGuardedAgainstBeingServedOverHttp(): void
    {
        (new StateStore($this->dir))->set(['secret' => 'abc']);

        self::assertFileExists($this->dir.'/.htaccess', 'Gdyby katalog stanu trafił do public/, sekret nie może wyjść przez serwer WWW.');
    }

    public function testWriteLeavesNoTemporaryFilesBehind(): void
    {
        $store = new StateStore($this->dir);
        for ($i = 0; $i < 5; ++$i) {
            $store->set(['licznik' => $i]);
        }

        self::assertSame([], glob($this->dir.'/.state-*.tmp') ?: [], 'Zapis atomowy sprząta po sobie plik tymczasowy.');
        self::assertSame(4, (new StateStore($this->dir))->get('licznik'));
    }
}
