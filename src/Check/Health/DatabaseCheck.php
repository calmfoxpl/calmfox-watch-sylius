<?php

declare(strict_types=1);

namespace Calmfox\WatchBundle\Check\Health;

use Calmfox\WatchBundle\Check\HealthCheckInterface;
use Calmfox\WatchBundle\Core\CheckResult;
use Doctrine\DBAL\Connection;

/**
 * Ping bazy. Brak bazy MA dać `fail` i kod 503, a nie wyjątek: adres kontrolny
 * jest wtedy jedynym miejscem, które potrafi powiedzieć, co się właściwie stało.
 */
final class DatabaseCheck implements HealthCheckInterface
{
    public function __construct(private readonly ?Connection $connection = null)
    {
    }

    public function run(): ?CheckResult
    {
        if (null === $this->connection) {
            return null;
        }

        $start = microtime(true);
        try {
            $one = $this->connection->executeQuery('SELECT 1')->fetchOne();
            $ms = self::ms($start);

            return '1' === (string) $one
                ? CheckResult::ok('db', 'Baza danych', null, $ms)
                : CheckResult::fail('db', 'Baza danych', 'Zapytanie testowe nie zwróciło wyniku.', $ms);
        } catch (\Throwable $e) {
            return CheckResult::fail('db', 'Baza danych', self::reason($e), self::ms($start));
        }
    }

    private static function ms(float $start): int
    {
        return (int) round((microtime(true) - $start) * 1000);
    }

    /**
     * Komunikat sterownika bywa dosłowny co do parametrów połączenia, a opis
     * checku jedzie do panelu i do powiadomień. Wycinamy więc wszystko, co
     * wygląda na hasło albo pełny adres połączenia.
     */
    private static function reason(\Throwable $e): string
    {
        $message = (string) preg_replace('/(password|passwd|pwd)\s*=\s*\S+/i', '$1=***', $e->getMessage());
        $message = (string) preg_replace('#([a-z0-9+.-]+://[^\s/@]+):[^\s/@]+@#i', '$1:***@', $message);

        return '' !== trim($message) ? $message : 'Połączenie z bazą danych nie doszło do skutku.';
    }
}
