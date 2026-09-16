<?php

declare(strict_types=1);

namespace Calmfox\WatchBundle\Check\Health;

use Calmfox\WatchBundle\Check\HealthCheckInterface;
use Calmfox\WatchBundle\Core\CheckResult;
use Calmfox\WatchBundle\Core\FileCache;
use Calmfox\WatchBundle\Core\MailerDsn;

/**
 * Poczta: UCZCIWIE test POŁĄCZENIA z serwerem (TCP, powitanie 220, EHLO),
 * nie test doręczenia. Tak też brzmi opis w panelu, bo klient, który przeczyta
 * „poczta działa", a nie dostanie potwierdzenia zamówienia, ma prawo czuć się
 * oszukany. Wynik trzymamy kwadrans, to najdroższy check w sekcji.
 */
final class SmtpCheck implements HealthCheckInterface
{
    private const LABEL = 'Wysyłka e-mail (SMTP)';
    private const CACHE_KEY = 'smtp';
    private const TIMEOUT = 2;

    public function __construct(
        private readonly ?string $mailerDsn,
        private readonly FileCache $cache,
    ) {
    }

    public function run(): ?CheckResult
    {
        $dsn = MailerDsn::parse($this->mailerDsn);

        if (MailerDsn::KIND_UNKNOWN === $dsn['kind']) {
            return CheckResult::warn('smtp', self::LABEL, '' === $dsn['scheme']
                ? 'Brak ustawionego adresu serwera poczty (MAILER_DSN), więc nie mamy czego sprawdzić.'
                : sprintf('Nieznany rodzaj transportu poczty (%s). Nie potrafimy go sprawdzić i nie udajemy, że potrafimy.', $dsn['scheme']));
        }
        if (MailerDsn::KIND_DISABLED === $dsn['kind']) {
            return CheckResult::warn('smtp', 'Wysyłka e-mail', 'Wysyłka poczty jest wyłączona w konfiguracji (null://). Sklep nie wyśle potwierdzeń zamówień.');
        }
        if (MailerDsn::KIND_LOCAL === $dsn['kind']) {
            return CheckResult::warn('smtp', 'Wysyłka e-mail', sprintf(
                'Wysyłka lokalna (%s), bez serwera SMTP. Bywa zawodna i nie da się jej sprawdzić z zewnątrz, a wiadomości często trafiają do niechcianych.',
                $dsn['scheme']
            ));
        }
        if (MailerDsn::KIND_SERVICE === $dsn['kind']) {
            return CheckResult::ok('smtp', 'Wysyłka e-mail', sprintf(
                'Wysyłka przez usługę %s. Test połączenia SMTP nie dotyczy tego transportu, więc go nie wykonujemy.',
                $dsn['service'] ?? $dsn['scheme']
            ));
        }

        $cached = $this->cache->get(self::CACHE_KEY);
        if (\is_array($cached) && isset($cached['status'], $cached['detail'])) {
            return CheckResult::of((string) $cached['status'], 'smtp', self::LABEL, (string) $cached['detail'], isset($cached['ms']) ? (int) $cached['ms'] : null);
        }

        $result = $this->probe((string) $dsn['host'], (int) $dsn['port'], (string) $dsn['secure']);
        $this->cache->set(self::CACHE_KEY, $result, 900);

        return CheckResult::of($result['status'], 'smtp', self::LABEL, $result['detail'], $result['ms']);
    }

    /** @return array{status: string, detail: string, ms: int} */
    private function probe(string $host, int $port, string $secure): array
    {
        $target = ('ssl' === $secure ? 'ssl://' : '').$host;
        $start = microtime(true);
        $errno = 0;
        $error = '';
        $socket = @fsockopen($target, $port, $errno, $error, self::TIMEOUT);

        if (!\is_resource($socket)) {
            return [
                'status' => CheckResult::FAIL,
                'detail' => sprintf('Nie można połączyć z %s:%d. %s', $host, $port, '' !== $error ? $error : 'Brak odpowiedzi.'),
                'ms' => (int) round((microtime(true) - $start) * 1000),
            ];
        }

        stream_set_timeout($socket, self::TIMEOUT);
        $greeting = (string) fgets($socket, 512);
        fwrite($socket, 'EHLO '.$host."\r\n");
        $ehlo = (string) fgets($socket, 512);
        fwrite($socket, "QUIT\r\n");
        fclose($socket);

        $fine = str_starts_with($greeting, '220') && str_starts_with($ehlo, '250');

        return [
            'status' => $fine ? CheckResult::OK : CheckResult::FAIL,
            'detail' => $fine
                ? sprintf('Serwer %s:%d przyjmuje połączenia. To test połączenia, nie doręczenia wiadomości.', $host, $port)
                : sprintf('Serwer %s odpowiada, ale nie po SMTP-owemu: „%s".', $host, trim('' !== $greeting ? $greeting : $ehlo)),
            'ms' => (int) round((microtime(true) - $start) * 1000),
        ];
    }
}
