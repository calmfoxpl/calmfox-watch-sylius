<?php

declare(strict_types=1);

namespace Calmfox\WatchBundle\Tests;

use Calmfox\WatchBundle\Core\ResponseSigner;
use PHPUnit\Framework\TestCase;

final class ResponseSignerTest extends TestCase
{
    private const SECRET = '0123456789abcdef0123456789abcdef';

    public function testProofMatchesTheFormulaFromTheContract(): void
    {
        $nonce = 'a1b2c3d4a1b2c3d4a1b2c3d4a1b2c3d4';
        $generatedAt = '2026-08-19T06:00:00+00:00';
        $body = '{"schema":1,"status":"ok"}';

        self::assertSame(
            hash_hmac('sha256', $nonce."\n".$generatedAt."\n".$body, self::SECRET),
            ResponseSigner::proof($nonce, $generatedAt, $body, self::SECRET)
        );
    }

    public function testProofChangesWithEveryPartOfTheInput(): void
    {
        $base = ResponseSigner::proof('nonce', '2026-08-19T06:00:00+00:00', '{"a":1}', self::SECRET);

        self::assertNotSame($base, ResponseSigner::proof('inny', '2026-08-19T06:00:00+00:00', '{"a":1}', self::SECRET));
        self::assertNotSame($base, ResponseSigner::proof('nonce', '2026-08-19T06:00:01+00:00', '{"a":1}', self::SECRET));
        self::assertNotSame($base, ResponseSigner::proof('nonce', '2026-08-19T06:00:00+00:00', '{"a":2}', self::SECRET));
        self::assertNotSame($base, ResponseSigner::proof('nonce', '2026-08-19T06:00:00+00:00', '{"a":1}', strrev(self::SECRET)));
    }

    public function testGeneratedAtIsIso8601Utc(): void
    {
        self::assertSame('2026-08-19T06:00:00+00:00', ResponseSigner::generatedAt(1787119200));
    }

    /**
     * Test kontraktowy z hubem: liczymy podpis naszym kodem i oddajemy go
     * do weryfikacji PRAWDZIWEJ metodzie huba (WpHealthClient::verifyProof).
     * Gdyby któraś strona zmieniła kolejność albo separator, ten test zgaśnie
     * jako pierwszy, zanim monitoring zacznie zgłaszać podstawioną treść.
     */
    public function testHubAcceptsOurProof(): void
    {
        $client = self::hubClient();
        $nonce = bin2hex(random_bytes(16));
        $body = ResponseSigner::encode(['schema' => 1, 'status' => 'ok', 'checks' => []]);
        $generatedAt = ResponseSigner::generatedAt();
        $proof = ResponseSigner::proof($nonce, $generatedAt, $body, self::SECRET);

        $verify = new \ReflectionMethod($client, 'verifyProof');
        $headers = ['x-calmfox-proof' => [$proof], 'x-calmfox-generated-at' => [$generatedAt]];

        self::assertSame('verified', $verify->invoke($client, $headers, $body, $nonce, self::SECRET));
        self::assertSame('invalid', $verify->invoke($client, $headers, $body.' ', $nonce, self::SECRET), 'Podpis liczy się nad dokładnymi bajtami odpowiedzi.');
        self::assertSame('invalid', $verify->invoke($client, $headers, $body, bin2hex(random_bytes(16)), self::SECRET), 'Znacznik jednorazowy huba jest częścią podpisu.');
        self::assertSame('unsigned', $verify->invoke($client, [], $body, $nonce, self::SECRET));
    }

    /** Odpowiedź sprzed pięciu minut to nagranie, nie pomiar, i hub tak ją traktuje. */
    public function testHubRejectsStaleProof(): void
    {
        $client = self::hubClient();
        $nonce = bin2hex(random_bytes(16));
        $body = ResponseSigner::encode(['schema' => 1, 'status' => 'ok']);
        $generatedAt = ResponseSigner::generatedAt(time() - 3600);
        $proof = ResponseSigner::proof($nonce, $generatedAt, $body, self::SECRET);

        $verify = new \ReflectionMethod($client, 'verifyProof');

        self::assertSame('invalid', $verify->invoke($client, ['x-calmfox-proof' => [$proof], 'x-calmfox-generated-at' => [$generatedAt]], $body, $nonce, self::SECRET));
    }

    private static function hubClient(): object
    {
        // Kod huba żyje w osobnym repozytorium, więc jego katalog wskazuje
        // CALMFOX_HUB_DIR. Bez niego test się pomija: podpis sprawdzamy wtedy
        // wyłącznie własnym kodem, a to za mało, żeby mówić o zgodności z hubem.
        $hub = (string) getenv('CALMFOX_HUB_DIR');
        $file = $hub.'/api/src/Plugin/WpHealthClient.php';
        if ('' === $hub || !is_file($file)) {
            self::markTestSkipped('Kod huba niedostępny — wskaż repozytorium huba przez CALMFOX_HUB_DIR.');
        }
        require_once $file;

        // Bez konstruktora: interesuje nas wyłącznie czysta weryfikacja podpisu,
        // a klient huba wymaga klienta HTTP i osłony anty-SSRF.
        return (new \ReflectionClass('App\Plugin\WpHealthClient'))->newInstanceWithoutConstructor();
    }
}
