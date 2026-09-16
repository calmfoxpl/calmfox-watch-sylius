<?php

declare(strict_types=1);

namespace Calmfox\WatchBundle\Tests;

use Calmfox\WatchBundle\Core\SecretManager;
use Calmfox\WatchBundle\Core\StateStore;
use PHPUnit\Framework\TestCase;

final class SecretManagerTest extends TestCase
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

    private function manager(): SecretManager
    {
        return new SecretManager(new StateStore($this->dir));
    }

    public function testSecretIsThirtyTwoHexCharactersAndStable(): void
    {
        $manager = $this->manager();
        $secret = $manager->secret();

        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $secret);
        self::assertSame($secret, $manager->secret(), 'Sekret raz wygenerowany nie może się zmieniać przy każdym odczycie.');
        self::assertSame($secret, $this->manager()->secret(), 'Sekret ma przetrwać między żądaniami, bo leży w pliku.');
    }

    public function testWrongKeyIsRejected(): void
    {
        $manager = $this->manager();
        $secret = $manager->secret();

        self::assertTrue($manager->accepts($secret));
        self::assertFalse($manager->accepts(''));
        self::assertFalse($manager->accepts(strrev($secret)));
        self::assertFalse($manager->accepts(substr($secret, 0, 31)));
    }

    public function testPreviousSecretStaysValidForFifteenMinutesAndThenExpires(): void
    {
        $manager = $this->manager();
        $old = $manager->secret();
        $new = $manager->rotate();

        self::assertNotSame($old, $new);
        self::assertTrue($manager->accepts($new), 'Nowy sekret działa od razu.');
        self::assertTrue($manager->accepts($old), 'Poprzedni sekret działa jeszcze w oknie rotacji.');
        self::assertTrue($manager->accepts($old, time() + SecretManager::PREVIOUS_WINDOW - 1));
        self::assertFalse($manager->accepts($old, time() + SecretManager::PREVIOUS_WINDOW + 1), 'Po kwadransie stary klucz przestaje działać.');
        self::assertTrue($manager->accepts($new, time() + 86400));
    }

    public function testPairingNonceIsReadableUntilItExpiresAndCanBeCleared(): void
    {
        $manager = $this->manager();

        self::assertSame('', $manager->pairingNonce());

        $nonce = $manager->makePairingNonce();
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $nonce);
        self::assertSame($nonce, $manager->pairingNonce());
        self::assertSame($nonce, $this->manager()->pairingNonce(), 'Challenge huba przychodzi osobnym żądaniem, więc znacznik musi leżeć w stanie.');
        self::assertSame('', $manager->pairingNonce(time() + SecretManager::PAIRING_TTL + 1));

        $manager->clearPairingNonce();
        self::assertSame('', $manager->pairingNonce());
    }

    public function testStateFileIsNotReadableByOthers(): void
    {
        $manager = $this->manager();
        $manager->secret();

        $path = (new StateStore($this->dir))->path();
        self::assertFileExists($path);
        self::assertSame(0, (fileperms($path) & 0077), 'W pliku leży sekret adresu kontrolnego, więc prawa muszą być 600.');
    }
}
