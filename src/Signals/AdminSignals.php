<?php

declare(strict_types=1);

namespace Calmfox\WatchBundle\Signals;

use Calmfox\WatchBundle\Core\AdminFingerprint;
use Calmfox\WatchBundle\Core\SecretManager;
use Doctrine\Persistence\ObjectRepository;

/**
 * Konta administracyjne sklepu. Sygnały jadą w sekcji `health` (odpytywanej
 * co minutę), a nie w `security` (raz na dobę), bo nagły przyrost kont
 * z pełnym dostępem to klasyczny objaw przejęcia panelu, i wolimy wiedzieć
 * o tym w ciągu minut niż nazajutrz.
 *
 * Loginów NIE wysyłamy: jedzie liczba, jednokierunkowy odcisk zbioru kont
 * (zmiana odcisku = zmiana składu) i data najnowszego konta. Kto to
 * konkretnie, właściciel sklepu widzi u siebie w panelu Syliusa.
 */
final class AdminSignals
{
    /** Tyle kont wystarczy do wykrycia zmiany składu, a zapytanie zostaje tanie. */
    private const LIMIT = 200;

    public function __construct(
        private readonly ?ObjectRepository $adminUsers,
        private readonly SecretManager $secrets,
    ) {
    }

    /**
     * @return array{count: int, identities: list<string>, newest: ?string, truncated: bool, available: bool}
     */
    public function snapshot(): array
    {
        $empty = ['count' => 0, 'identities' => [], 'newest' => null, 'truncated' => false, 'available' => false];
        if (null === $this->adminUsers) {
            return $empty;
        }

        try {
            // Przez repozytorium, nie zapytaniem SQL: nazwa encji i tabeli bywa
            // podmieniana w projekcie, a repozytorium zawsze wskazuje tę właściwą.
            $admins = $this->adminUsers->findBy(['enabled' => true], ['id' => 'ASC'], self::LIMIT + 1);
        } catch (\Throwable) {
            return $empty;
        }

        $truncated = \count($admins) > self::LIMIT;
        $admins = \array_slice($admins, 0, self::LIMIT);

        $identities = [];
        $newest = null;
        foreach ($admins as $admin) {
            $identities[] = self::identity($admin);
            $created = self::createdAt($admin);
            if (null !== $created && (null === $newest || $created > $newest)) {
                $newest = $created;
            }
        }

        return [
            'count' => \count($identities),
            'identities' => $identities,
            'newest' => $newest?->format('c'),
            'truncated' => $truncated,
            'available' => true,
        ];
    }

    /** @return array{adminCount: ?int, adminsFingerprint: ?string, newestAdminAt: ?string} */
    public function signals(): array
    {
        $snapshot = $this->snapshot();
        if (!$snapshot['available']) {
            return ['adminCount' => null, 'adminsFingerprint' => null, 'newestAdminAt' => null];
        }

        return [
            'adminCount' => $snapshot['count'],
            'adminsFingerprint' => AdminFingerprint::of($snapshot['identities'], $this->secrets->secret()),
            'newestAdminAt' => $snapshot['newest'],
        ];
    }

    private static function identity(object $admin): string
    {
        $id = method_exists($admin, 'getId') ? (string) $admin->getId() : '';
        $name = method_exists($admin, 'getUsername') ? (string) $admin->getUsername() : '';
        if ('' === $name && method_exists($admin, 'getEmail')) {
            $name = (string) $admin->getEmail();
        }

        return $id.':'.$name;
    }

    private static function createdAt(object $admin): ?\DateTimeInterface
    {
        if (!method_exists($admin, 'getCreatedAt')) {
            return null;
        }
        $value = $admin->getCreatedAt();

        return $value instanceof \DateTimeInterface ? $value : null;
    }
}
