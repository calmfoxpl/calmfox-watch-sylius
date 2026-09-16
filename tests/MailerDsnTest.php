<?php

declare(strict_types=1);

namespace Calmfox\WatchBundle\Tests;

use Calmfox\WatchBundle\Core\MailerDsn;
use PHPUnit\Framework\TestCase;

final class MailerDsnTest extends TestCase
{
    public function testPlainSmtpUsesPortTwentyFiveByDefault(): void
    {
        $dsn = MailerDsn::parse('smtp://mail.example.com');

        self::assertSame(MailerDsn::KIND_SMTP, $dsn['kind']);
        self::assertSame('mail.example.com', $dsn['host']);
        self::assertSame(25, $dsn['port']);
        self::assertSame('', $dsn['secure']);
    }

    public function testSmtpsUsesImplicitTlsOnPortFourSixtyFive(): void
    {
        $dsn = MailerDsn::parse('smtps://uzytkownik:tajne@poczta.example.com');

        self::assertSame(MailerDsn::KIND_SMTP, $dsn['kind']);
        self::assertSame('poczta.example.com', $dsn['host']);
        self::assertSame(465, $dsn['port']);
        self::assertSame('ssl', $dsn['secure']);
    }

    public function testExplicitPortAndCredentialsWithSpecialCharacters(): void
    {
        $dsn = MailerDsn::parse('smtp://sklep%40example.com:p%40ss%3Aword@smtp.example.com:587');

        self::assertSame('smtp.example.com', $dsn['host']);
        self::assertSame(587, $dsn['port']);
    }

    /** Hasło z adresu nie może wyciec do opisu checku, bo ten jedzie do panelu. */
    public function testCredentialsAreNeverReturned(): void
    {
        $dsn = MailerDsn::parse('smtp://admin:BardzoTajneHaslo@smtp.example.com:2525');

        self::assertStringNotContainsString('BardzoTajneHaslo', json_encode($dsn, \JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('admin', json_encode($dsn, \JSON_THROW_ON_ERROR));
    }

    public function testLocalAndDisabledTransports(): void
    {
        self::assertSame(MailerDsn::KIND_LOCAL, MailerDsn::parse('sendmail://default')['kind']);
        self::assertSame(MailerDsn::KIND_LOCAL, MailerDsn::parse('native://default')['kind']);
        self::assertSame(MailerDsn::KIND_DISABLED, MailerDsn::parse('null://null')['kind']);
    }

    public function testProviderApiTransportIsNotTreatedAsSmtp(): void
    {
        $dsn = MailerDsn::parse('brevo+api://KLUCZ@default');

        self::assertSame(MailerDsn::KIND_SERVICE, $dsn['kind']);
        self::assertSame('brevo', $dsn['service']);
        self::assertNull($dsn['port'], 'Nie udajemy pomiaru tam, gdzie nie ma czego mierzyć.');
    }

    /**
     * Mostek dostawcy z hostem „default" NIE zamienia się w test SMTP: hosta
     * zna tylko biblioteka mostka, a zgadnięty adres skończyłby się fałszywą
     * awarią. Jawny host testujemy normalnie.
     */
    public function testProviderSmtpBridgeIsOnlyProbedWithExplicitHost(): void
    {
        self::assertSame(MailerDsn::KIND_SERVICE, MailerDsn::parse('sendgrid+smtp://KLUCZ@default')['kind']);

        $explicit = MailerDsn::parse('brevo+smtp://KLUCZ@smtp-relay.example.com');
        self::assertSame(MailerDsn::KIND_SMTP, $explicit['kind']);
        self::assertSame('smtp-relay.example.com', $explicit['host']);
        self::assertSame(587, $explicit['port']);
    }

    public function testFailoverTakesTheFirstTransport(): void
    {
        $dsn = MailerDsn::parse('failover(smtp://pierwszy.example.com:2525 smtp://drugi.example.com)');

        self::assertSame(MailerDsn::KIND_SMTP, $dsn['kind']);
        self::assertSame('pierwszy.example.com', $dsn['host']);
        self::assertSame(2525, $dsn['port']);
    }

    public function testEmptyAndUnknownDsn(): void
    {
        self::assertSame(MailerDsn::KIND_UNKNOWN, MailerDsn::parse(null)['kind']);
        self::assertSame('', MailerDsn::parse('')['scheme']);
        self::assertSame(MailerDsn::KIND_UNKNOWN, MailerDsn::parse('cokolwiek://host')['kind']);
    }

    public function testIpv6HostWithPort(): void
    {
        $dsn = MailerDsn::parse('smtp://[::1]:1025');

        self::assertSame('::1', $dsn['host']);
        self::assertSame(1025, $dsn['port']);
    }
}
