<?php

declare(strict_types=1);

namespace Calmfox\WatchBundle\Check\Health;

use Calmfox\WatchBundle\Check\HealthCheckInterface;
use Calmfox\WatchBundle\Core\CheckResult;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Pula pamięci podręcznej aplikacji: zapis i odczyt klucza kontrolnego.
 * Sam fakt, że usługa odpowiada, nie wystarcza. Widzieliśmy Redisa, który
 * przyjmował połączenia i po cichu odrzucał zapisy (pełna pamięć), a sklep
 * przy każdym żądaniu budował katalog od zera.
 */
final class AppCacheCheck implements HealthCheckInterface
{
    public function __construct(private readonly ?CacheItemPoolInterface $pool = null)
    {
    }

    public function run(): ?CheckResult
    {
        if (null === $this->pool) {
            return null;
        }

        $label = 'Pamięć podręczna aplikacji';
        $backend = self::backendName($this->pool);
        if (null !== $backend) {
            $label .= ' ('.$backend.')';
        }

        $start = microtime(true);
        $expected = bin2hex(random_bytes(8));
        try {
            $item = $this->pool->getItem('calmfox_watch_ping');
            $item->set($expected);
            $item->expiresAfter(60);
            $this->pool->save($item);
            $back = $this->pool->getItem('calmfox_watch_ping')->get();
        } catch (\Throwable $e) {
            return CheckResult::fail('app_cache', $label, sprintf('Pamięć podręczna nie przyjmuje zapisu: %s', $e->getMessage()), self::ms($start));
        }

        $ms = self::ms($start);

        return $back === $expected
            ? CheckResult::ok('app_cache', $label, null, $ms)
            : CheckResult::fail('app_cache', $label, 'Zapis i odczyt nie zgadzają się. Usługa pamięci podręcznej mogła przestać działać, sklep będzie liczyć wszystko od nowa przy każdym wejściu.', $ms);
    }

    /**
     * Nazwa silnika po ludzku. Pula bywa opakowana (znacznikami, śledzeniem
     * w trybie deweloperskim), więc rozwijamy opakowania, zamiast pokazywać
     * klientowi nazwę klasy dekoratora.
     */
    private static function backendName(CacheItemPoolInterface $pool): ?string
    {
        $seen = 0;
        while ($seen++ < 5) {
            $class = $pool::class;
            foreach (['Redis' => 'Redis', 'Memcached' => 'Memcached', 'Filesystem' => 'plik', 'Apcu' => 'APCu', 'PdoAdapter' => 'baza danych', 'Array' => 'pamięć procesu'] as $needle => $name) {
                if (str_contains($class, $needle)) {
                    return $name;
                }
            }
            $inner = self::unwrap($pool);
            if (null === $inner) {
                return null;
            }
            $pool = $inner;
        }

        return null;
    }

    private static function unwrap(CacheItemPoolInterface $pool): ?CacheItemPoolInterface
    {
        foreach (['pool', 'adapter', 'decorated', 'inner'] as $name) {
            try {
                $property = new \ReflectionProperty($pool, $name);
            } catch (\ReflectionException) {
                continue;
            }
            $value = $property->isInitialized($pool) ? $property->getValue($pool) : null;
            if ($value instanceof CacheItemPoolInterface) {
                return $value;
            }
        }

        return null;
    }

    private static function ms(float $start): int
    {
        return (int) round((microtime(true) - $start) * 1000);
    }
}
