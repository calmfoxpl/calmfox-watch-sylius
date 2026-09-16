<?php

declare(strict_types=1);

namespace Calmfox\WatchBundle\Tests;

use Calmfox\WatchBundle\Core\SecretManager;
use Calmfox\WatchBundle\Core\StateStore;
use Calmfox\WatchBundle\Signals\PackageSignals;
use PHPUnit\Framework\TestCase;

final class PackageSignalsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/calmfox-watch-test-'.bin2hex(random_bytes(6));
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

    /** @param array<string, string> $bundles */
    private function signals(array $bundles): array
    {
        return (new PackageSignals($bundles, new SecretManager(new StateStore($this->dir))))->signals();
    }

    /** @param list<string> $names */
    private static function bundles(array $names): array
    {
        $map = [];
        foreach ($names as $name) {
            $map[$name] = 'Vendor\\'.$name;
        }

        return $map;
    }

    public function testNamesGoOutSortedSoTheFingerprintDoesNotDependOnBundleOrder(): void
    {
        $first = $this->signals(self::bundles(['SyliusCoreBundle', 'FrameworkBundle', 'CalmfoxWatchBundle']));
        $second = $this->signals(self::bundles(['CalmfoxWatchBundle', 'SyliusCoreBundle', 'FrameworkBundle']));

        self::assertSame(['CalmfoxWatchBundle', 'FrameworkBundle', 'SyliusCoreBundle'], $first['activePlugins']);
        self::assertSame($first['pluginsFingerprint'], $second['pluginsFingerprint'], 'Przestawienie wpisów w bundles.php nie jest zmianą składu.');
    }

    public function testDisabledBundleChangesTheFingerprint(): void
    {
        $before = $this->signals(self::bundles(['FrameworkBundle', 'SecurityBundle', 'SyliusCoreBundle']));
        $after = $this->signals(self::bundles(['FrameworkBundle', 'SyliusCoreBundle']));

        self::assertNotSame($before['pluginsFingerprint'], $after['pluginsFingerprint']);
        self::assertSame(2, $after['pluginCount']);
        self::assertNotContains('SecurityBundle', $after['activePlugins']);
    }

    public function testFingerprintIsSaltedWithTheInstallationSecret(): void
    {
        $here = $this->signals(self::bundles(['FrameworkBundle']));

        $other = sys_get_temp_dir().'/calmfox-watch-test-'.bin2hex(random_bytes(6));
        $elsewhere = (new PackageSignals(self::bundles(['FrameworkBundle']), new SecretManager(new StateStore($other))))->signals();
        foreach (glob($other.'/{,.}*', \GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        @rmdir($other);

        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $here['pluginsFingerprint']);
        self::assertNotSame($here['pluginsFingerprint'], $elsewhere['pluginsFingerprint'], 'Ten sam skład w dwóch sklepach nie może dawać tego samego odcisku.');
    }

    /** Hub przyjmuje 100 nazw, ale odcisk ma widzieć wszystko, co jest włączone. */
    public function testNameListIsCappedWhileTheFingerprintCoversEverything(): void
    {
        $many = [];
        for ($i = 1; $i <= 120; ++$i) {
            $many[] = sprintf('Bundle%03d', $i);
        }

        $full = $this->signals(self::bundles($many));
        array_pop($many);
        $trimmed = $this->signals(self::bundles($many));

        self::assertSame(120, $full['pluginCount']);
        self::assertCount(100, $full['activePlugins']);
        self::assertSame($full['activePlugins'], $trimmed['activePlugins'], 'Zdjęty bundle jest poza setką nazw…');
        self::assertNotSame($full['pluginsFingerprint'], $trimmed['pluginsFingerprint'], '…ale odcisk musi to zobaczyć.');
    }

    /** Sylius nie ma automatycznych aktualizacji, więc nie mamy o czym meldować. */
    public function testAutoUpdatesFieldIsNeverInvented(): void
    {
        self::assertSame(
            ['pluginCount', 'pluginsFingerprint', 'activePlugins'],
            array_keys($this->signals(self::bundles(['FrameworkBundle'])))
        );
    }
}
