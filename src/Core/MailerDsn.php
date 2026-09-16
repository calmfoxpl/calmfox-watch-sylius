<?php

declare(strict_types=1);

namespace Calmfox\WatchBundle\Core;

/**
 * Rozbiór MAILER_DSN na tyle, ile potrzeba do UCZCIWEGO checku poczty.
 * Zasada: testujemy tylko to, co da się przetestować. Adres SMTP z jawnym
 * hostem sprawdzamy połączeniem, a przy transporcie usługi (API dostawcy,
 * sendmail, host „default" w mostkach Symfony) mówimy wprost, że test SMTP
 * nie dotyczy. Zgadywanie hosta dostawcy skończyłoby się fałszywą awarią,
 * a fałszywa awaria budzi ludzi w nocy po nic.
 *
 * Loginu ani hasła z DSN nie zwracamy nigdzie: opis checku jedzie do panelu.
 */
final class MailerDsn
{
    /** Serwer SMTP z jawnym hostem: można nawiązać połączenie i zmierzyć czas. */
    public const KIND_SMTP = 'smtp';
    /** Wysyłka lokalna (sendmail, ustawienia php.ini): nie ma z czym się łączyć. */
    public const KIND_LOCAL = 'local';
    /** Transport usługi (API dostawcy albo mostek z hostem „default"). */
    public const KIND_SERVICE = 'service';
    /** Wysyłka wyłączona (null://). */
    public const KIND_DISABLED = 'disabled';
    /** Brak MAILER_DSN albo schemat, którego nie znamy. */
    public const KIND_UNKNOWN = 'unknown';

    /**
     * @return array{kind: string, scheme: string, service: ?string, host: ?string, port: ?int, secure: string}
     */
    public static function parse(?string $dsn): array
    {
        $dsn = trim((string) $dsn);
        if ('' === $dsn) {
            return self::result(self::KIND_UNKNOWN, '');
        }

        // failover(smtp://a smtp://b) i roundrobin(...): bierzemy pierwszy transport,
        // bo to on obsługuje wysyłkę, dopóki działa.
        if (1 === preg_match('/^(failover|roundrobin)\((.*)\)$/s', $dsn, $m)) {
            $first = preg_split('/\s+/', trim($m[2]))[0] ?? '';

            return self::parse($first);
        }

        $position = strpos($dsn, '://');
        if (false === $position) {
            return self::result(self::KIND_UNKNOWN, '');
        }
        $scheme = mb_strtolower(substr($dsn, 0, $position));
        $rest = substr($dsn, $position + 3);

        // Odcinamy ścieżkę i parametry, potem dane logowania (wszystko przed ostatnim @).
        $authority = (string) (preg_split('/[\/?#]/', $rest, 2)[0] ?? '');
        $at = strrpos($authority, '@');
        if (false !== $at) {
            $authority = substr($authority, $at + 1);
        }
        [$host, $port] = self::splitHostPort($authority);

        if ('null' === $scheme) {
            return self::result(self::KIND_DISABLED, $scheme);
        }
        if (\in_array($scheme, ['sendmail', 'native'], true)) {
            return self::result(self::KIND_LOCAL, $scheme);
        }
        if ('smtp' === $scheme || 'smtps' === $scheme) {
            $secure = 'smtps' === $scheme ? 'ssl' : '';

            return self::result(self::KIND_SMTP, $scheme, null, $host, $port ?? ('' === $secure ? 25 : 465), $secure);
        }

        // Mostki dostawców: brevo+api, sendgrid+smtp, ses+https itd.
        if (str_contains($scheme, '+')) {
            [$service, $transport] = explode('+', $scheme, 2);
            if ('smtp' === $transport && null !== $host && 'default' !== $host) {
                return self::result(self::KIND_SMTP, $scheme, $service, $host, $port ?? 587, '');
            }

            return self::result(self::KIND_SERVICE, $scheme, $service);
        }

        return self::result(self::KIND_UNKNOWN, $scheme);
    }

    /** @return array{0: ?string, 1: ?int} */
    private static function splitHostPort(string $authority): array
    {
        if ('' === $authority) {
            return [null, null];
        }
        // IPv6 w nawiasach kwadratowych: [::1]:1025
        if (1 === preg_match('/^\[([^\]]+)\](?::(\d+))?$/', $authority, $m)) {
            return [$m[1], isset($m[2]) ? (int) $m[2] : null];
        }
        $colon = strrpos($authority, ':');
        if (false === $colon) {
            return [$authority, null];
        }

        return [substr($authority, 0, $colon), (int) substr($authority, $colon + 1)];
    }

    /**
     * @return array{kind: string, scheme: string, service: ?string, host: ?string, port: ?int, secure: string}
     */
    private static function result(string $kind, string $scheme, ?string $service = null, ?string $host = null, ?int $port = null, string $secure = ''): array
    {
        return [
            'kind' => $kind,
            'scheme' => $scheme,
            'service' => $service,
            'host' => $host,
            'port' => $port,
            'secure' => $secure,
        ];
    }
}
