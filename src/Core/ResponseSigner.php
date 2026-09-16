<?php

declare(strict_types=1);

namespace Calmfox\WatchBundle\Core;

/**
 * Dowód świeżości odpowiedzi. Hub dokłada do żądania znacznik jednorazowy,
 * a my podpisujemy nim treść kluczem instalacji. Podpis idzie NAGŁÓWKIEM,
 * nie w treści, bo liczymy go nad dokładnie tymi bajtami, które wychodzą
 * na łącze: gdyby siedział w JSON-ie, trzeba by zgadywać, jak framework
 * poskłada odpowiedź po podmianie pola.
 *
 * Granica ochrony, mówimy o niej wprost: kto ma sekret z serwera, potrafi
 * podpisać kłamstwo. To odcina tanie ataki (podstawiony plik statyczny,
 * odpowiedź z pamięci podręcznej, powtórka sprzed przejęcia sklepu),
 * a nie zastępuje odzyskiwania serwera.
 */
final class ResponseSigner
{
    public const PROOF_HEADER = 'X-Calmfox-Proof';
    public const GENERATED_HEADER = 'X-Calmfox-Generated-At';

    /** Znacznik czasu w tym samym formacie, którego oczekuje hub (ISO 8601, UTC). */
    public static function generatedAt(?int $now = null): string
    {
        return gmdate('c', $now ?? time());
    }

    public static function proof(string $nonce, string $generatedAt, string $body, string $secret): string
    {
        return hash_hmac('sha256', $nonce."\n".$generatedAt."\n".$body, $secret);
    }

    /**
     * Bajty odpowiedzi. Bez escapowania unicode i ukośników, żeby polskie opisy
     * checków dało się przeczytać w dziennikach po obu stronach.
     *
     * @param array<string, mixed> $payload
     */
    public static function encode(array $payload, bool $pretty = false): string
    {
        $flags = \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES;
        if ($pretty) {
            $flags |= \JSON_PRETTY_PRINT;
        }

        return (string) json_encode($payload, $flags);
    }
}
