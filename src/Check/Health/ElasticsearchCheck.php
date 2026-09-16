<?php

declare(strict_types=1);

namespace Calmfox\WatchBundle\Check\Health;

use Calmfox\WatchBundle\Check\HealthCheckInterface;
use Calmfox\WatchBundle\Core\CheckResult;
use Calmfox\WatchBundle\Core\FileCache;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Elasticsearch: check OPCJONALNY. Bez podanego adresu w ogóle nie powstaje,
 * bo sklep bez wyszukiwarki nie ma jak mieć jej awarii. Gdy jest używana,
 * jej awaria wywraca wyszukiwanie i listingi kategorii, czyli sprzedaż.
 */
final class ElasticsearchCheck implements HealthCheckInterface
{
    private const LABEL = 'Elasticsearch';
    private const CACHE_KEY = 'elasticsearch';
    private const TIMEOUT = 2;

    public function __construct(
        private readonly ?string $url,
        private readonly HttpClientInterface $httpClient,
        private readonly FileCache $cache,
    ) {
    }

    public function run(): ?CheckResult
    {
        if (null === $this->url || '' === trim($this->url)) {
            return null;
        }

        $cached = $this->cache->get(self::CACHE_KEY);
        if (\is_array($cached) && isset($cached['status'])) {
            return CheckResult::of((string) $cached['status'], 'elasticsearch', self::LABEL, isset($cached['detail']) ? (string) $cached['detail'] : null, isset($cached['ms']) ? (int) $cached['ms'] : null);
        }

        $start = microtime(true);
        try {
            $response = $this->httpClient->request('GET', rtrim($this->url, '/').'/_cluster/health', [
                'timeout' => self::TIMEOUT,
                'max_duration' => self::TIMEOUT + 1,
            ]);
            $body = json_decode($response->getContent(false), true);
            $cluster = \is_array($body) && \is_string($body['status'] ?? null) ? $body['status'] : '';
        } catch (\Throwable $e) {
            $result = ['status' => CheckResult::FAIL, 'detail' => sprintf('Klaster nie odpowiada: %s', $e->getMessage()), 'ms' => self::ms($start)];
            $this->cache->set(self::CACHE_KEY, $result, 300);

            return CheckResult::of($result['status'], 'elasticsearch', self::LABEL, $result['detail'], $result['ms']);
        }

        $result = [
            'status' => '' === $cluster || 'red' === $cluster ? CheckResult::FAIL : ('yellow' === $cluster ? CheckResult::WARN : CheckResult::OK),
            'detail' => '' === $cluster ? 'Odpowiedź klastra bez pola status.' : sprintf('Stan klastra: %s.', $cluster),
            'ms' => self::ms($start),
        ];
        $this->cache->set(self::CACHE_KEY, $result, 300);

        return CheckResult::of($result['status'], 'elasticsearch', self::LABEL, $result['detail'], $result['ms']);
    }

    private static function ms(float $start): int
    {
        return (int) round((microtime(true) - $start) * 1000);
    }
}
