<?php

declare(strict_types=1);

namespace Calmfox\WatchBundle\Check;

use Calmfox\WatchBundle\Core\CheckNormalizer;
use Psr\Log\LoggerInterface;

/**
 * Uruchamia komplet checków jednej sekcji. Wyjątek z pojedynczego checku nie
 * może wywrócić całej odpowiedzi: monitoring wolałby usłyszeć o dziewięciu
 * usługach niż o żadnej. Nasze checki łapią błędy u siebie i zamieniają je
 * na status `fail`, więc tutaj cicho pomijamy tylko to, co rozsypało się
 * w rozszerzeniu klienta (z wpisem do dziennika zdarzeń).
 */
final class CheckRunner
{
    /** @param iterable<HealthCheckInterface> $checks */
    public function __construct(
        private readonly iterable $checks,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /** @return array{status: string, checks: list<array<string, mixed>>} */
    public function run(): array
    {
        $results = [];
        foreach ($this->checks as $check) {
            try {
                $result = $check->run();
            } catch (\Throwable $e) {
                $this->logger?->warning('Check Calmfox Watch zakończył się wyjątkiem: {check} {error}', [
                    'check' => $check::class,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }
            if (null !== $result) {
                $results[] = $result;
            }
        }

        $normalized = CheckNormalizer::normalize($results);

        return ['status' => CheckNormalizer::aggregate($normalized), 'checks' => $normalized];
    }
}
