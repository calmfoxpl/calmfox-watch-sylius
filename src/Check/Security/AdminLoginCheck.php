<?php

declare(strict_types=1);

namespace Calmfox\WatchBundle\Check\Security;

use Calmfox\WatchBundle\Check\HealthCheckInterface;
use Calmfox\WatchBundle\Core\CheckResult;
use Doctrine\Persistence\ObjectRepository;

/**
 * Konta o oczywistym loginie. Pierwszy cel ataków słownikowych, a przy okazji
 * najczęstsza pozostałość po wgraniu danych przykładowych Syliusa na serwer,
 * który potem został produkcją.
 */
final class AdminLoginCheck implements HealthCheckInterface
{
    private const USERNAMES = ['admin', 'administrator', 'sylius', 'test'];
    private const EMAILS = ['admin@example.com', 'sylius@example.com', 'admin@admin.com', 'admin@localhost'];

    public function __construct(private readonly ?ObjectRepository $adminUsers = null)
    {
    }

    public function run(): ?CheckResult
    {
        if (null === $this->adminUsers) {
            return null;
        }

        $found = [];
        try {
            foreach (self::USERNAMES as $username) {
                if (null !== $this->adminUsers->findOneBy(['username' => $username])) {
                    $found[] = $username;
                }
            }
            foreach (self::EMAILS as $email) {
                if (null !== $this->adminUsers->findOneBy(['email' => $email])) {
                    $found[] = $email;
                }
            }
        } catch (\Throwable) {
            return null;
        }

        $found = array_values(array_unique($found));

        return CheckResult::of([] !== $found ? CheckResult::WARN : CheckResult::OK, 'admin_login', 'Konta o domyślnym loginie', [] !== $found
            ? sprintf('Istnieją konta o łatwych do odgadnięcia danych: %s. Zmień login albo wyłącz konto, jeżeli zostało po danych przykładowych.', implode(', ', $found))
            : 'Brak kont o domyślnych loginach.',
            // Polecenia świadomie nie ma: skasowanie konta administracyjnego zanim
            // zastępcze naprawdę działa to prosta droga do zamknięcia się na zewnątrz.
            fix: 'Załóż konto z własnym adresem, sprawdź logowanie na nie, a dopiero potem wyłącz konto o domyślnym loginie. Konta z danych przykładowych po prostu usuń.');
    }
}
