<?php

declare(strict_types=1);

namespace Calmfox\WatchBundle\Tests;

use Calmfox\WatchBundle\Core\AdminFingerprint;
use PHPUnit\Framework\TestCase;

final class AdminFingerprintTest extends TestCase
{
    private const SECRET = '0123456789abcdef0123456789abcdef';

    public function testSameSetOfAccountsGivesSameFingerprintRegardlessOfOrder(): void
    {
        $first = AdminFingerprint::of(['1:anna', '4:magazyn', '9:marketing'], self::SECRET);
        $second = AdminFingerprint::of(['9:marketing', '1:anna', '4:magazyn'], self::SECRET);

        self::assertSame($first, $second, 'Kolejność z bazy nie może zmieniać odcisku, inaczej hub zgłaszałby zmianę składu bez powodu.');
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $first);
    }

    public function testAddedRemovedOrRenamedAccountChangesFingerprint(): void
    {
        $base = AdminFingerprint::of(['1:anna', '4:magazyn'], self::SECRET);

        self::assertNotSame($base, AdminFingerprint::of(['1:anna', '4:magazyn', '11:nowy'], self::SECRET), 'Nowe konto administratora to sygnał, o który w tym całym mechanizmie chodzi.');
        self::assertNotSame($base, AdminFingerprint::of(['1:anna'], self::SECRET));
        self::assertNotSame($base, AdminFingerprint::of(['1:anna', '4:magazynier'], self::SECRET));
    }

    /** Sól z sekretu instalacji: odcisk jest bezużyteczny poza tym jednym sklepem. */
    public function testFingerprintIsSaltedWithInstallationSecret(): void
    {
        self::assertNotSame(
            AdminFingerprint::of(['1:admin'], self::SECRET),
            AdminFingerprint::of(['1:admin'], strrev(self::SECRET))
        );
    }

    public function testFingerprintNeverContainsLogins(): void
    {
        self::assertStringNotContainsString('anna', AdminFingerprint::of(['1:anna'], self::SECRET));
    }
}
