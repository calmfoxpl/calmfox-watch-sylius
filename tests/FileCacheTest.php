<?php

declare(strict_types=1);

namespace Calmfox\WatchBundle\Tests;

use Calmfox\WatchBundle\Core\FileCache;
use PHPUnit\Framework\TestCase;

final class FileCacheTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/calmfox-watch-cache-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        foreach (glob($this->dir.'/.*') ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        @rmdir($this->dir);
    }

    public function testStoresAndReadsBackStructuredValues(): void
    {
        $cache = new FileCache($this->dir);
        $cache->set('payload-health', ['status' => 'ok', 'checks' => []], 60);

        self::assertSame(['status' => 'ok', 'checks' => []], (new FileCache($this->dir))->get('payload-health'));
    }

    public function testExpiredEntryReadsAsMissing(): void
    {
        $cache = new FileCache($this->dir);
        $cache->set('smtp', ['status' => 'ok'], -1);

        self::assertNull($cache->get('smtp'));
    }

    public function testMissingEntryAndDeleteBehaveQuietly(): void
    {
        $cache = new FileCache($this->dir);

        self::assertNull($cache->get('nie-ma'));
        $cache->delete('nie-ma');
        $cache->set('jest', 1, 60);
        $cache->delete('jest');
        self::assertNull($cache->get('jest'));
    }

    public function testKeysWithUnusualCharactersCannotEscapeTheDirectory(): void
    {
        $cache = new FileCache($this->dir);
        $cache->set('../../etc/passwd', 'x', 60);

        self::assertSame([], glob($this->dir.'/../../etc/passwd') ?: []);
        self::assertSame('x', $cache->get('../../etc/passwd'));
    }
}
