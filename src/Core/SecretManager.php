<?php

declare(strict_types=1);

namespace Calmfox\WatchBundle\Core;

/**
 * Sekret adresu kontrolnego (model pull huba) i znacznik parowania.
 * Rotacja trzyma poprzedni sekret przez 15 minut: gdyby przepięcie w hubie
 * się nie powiodło, monitoring nie może zerwać się w połowie operacji.
 */
final class SecretManager
{
    /** Ważność poprzedniego sekretu po rotacji (sekundy). */
    public const PREVIOUS_WINDOW = 900;
    /** Ważność znacznika parowania: hub robi challenge od razu, kwadrans wystarcza z zapasem. */
    public const PAIRING_TTL = 900;
    /** Ważność znacznika łączenia przez panel: tyle czasu ma człowiek na zalogowanie się i wybór organizacji. */
    public const CONNECT_TTL = 900;

    private const KEY = 'secret';
    private const PREV = 'previousSecret';
    private const PREV_UNTIL = 'previousSecretUntil';
    private const PAIRING = 'pairingNonce';
    private const PAIRING_UNTIL = 'pairingNonceUntil';
    private const CONNECT = 'connectState';
    private const CONNECT_UNTIL = 'connectStateUntil';
    private const LAST_POLL = 'lastPollAt';

    public function __construct(private readonly StateStore $state)
    {
    }

    /** Sekret instalacji: 16 losowych bajtów, czyli 32 znaki hex (128 bitów). */
    public function secret(): string
    {
        $secret = (string) $this->state->get(self::KEY, '');
        if ('' === $secret) {
            $secret = self::random();
            $this->state->set([self::KEY => $secret]);
        }

        return $secret;
    }

    /** Nowy sekret działa od razu, poprzedni jeszcze przez kwadrans. */
    public function rotate(): string
    {
        $fresh = self::random();
        $this->state->set([
            self::KEY => $fresh,
            self::PREV => (string) $this->state->get(self::KEY, ''),
            self::PREV_UNTIL => time() + self::PREVIOUS_WINDOW,
        ]);

        return $fresh;
    }

    /** Klucz z żądania kontra sekret bieżący albo poprzedni w oknie rotacji. */
    public function accepts(string $key, ?int $now = null): bool
    {
        if ('' === $key) {
            return false;
        }
        $now ??= time();

        $current = (string) $this->state->get(self::KEY, '');
        if ('' !== $current && hash_equals($current, $key)) {
            return true;
        }

        $previous = (string) $this->state->get(self::PREV, '');
        $until = (int) $this->state->get(self::PREV_UNTIL, 0);

        return '' !== $previous && $now <= $until && hash_equals($previous, $key);
    }

    /**
     * Znacznik parowania zapisujemy PRZED wysłaniem żądania do huba: hub w trakcie
     * obsługi odpytuje nasz adres kontrolny i oczekuje echa, więc gdyby zapis szedł
     * po odpowiedzi, challenge trafiłby na pustkę.
     */
    public function makePairingNonce(): string
    {
        $nonce = self::random();
        $this->state->set([self::PAIRING => $nonce, self::PAIRING_UNTIL => time() + self::PAIRING_TTL]);

        return $nonce;
    }

    public function pairingNonce(?int $now = null): string
    {
        $until = (int) $this->state->get(self::PAIRING_UNTIL, 0);

        return ($now ?? time()) <= $until ? (string) $this->state->get(self::PAIRING, '') : '';
    }

    public function clearPairingNonce(): void
    {
        $this->state->remove(self::PAIRING, self::PAIRING_UNTIL);
    }

    /**
     * Znacznik łączenia przez panel. Powstaje PRZED wyjściem do panelu i jest
     * jedynym dowodem, że powrót z kluczem instalacyjnym jest odpowiedzią na to
     * konkretne kliknięcie. Bez niego wystarczyłoby podrzucić administratorowi
     * link z cudzym kluczem, żeby podpiąć ten sklep pod obce konto.
     *
     * Znaki są z zakresu [a-f0-9], bo panel przepuszcza wyłącznie znacznik
     * alfanumeryczny o długości od 12 do 64 znaków.
     */
    public function makeConnectState(): string
    {
        $state = bin2hex(random_bytes(12));
        $this->state->set([self::CONNECT => $state, self::CONNECT_UNTIL => time() + self::CONNECT_TTL]);

        return $state;
    }

    /**
     * Zużycie znacznika: porównanie w stałym czasie i skasowanie NIEZALEŻNIE od
     * wyniku. Znacznik ma być jednorazowy, więc nieudana próba też go pali:
     * inaczej dałoby się go zgadywać w kółko tym samym powrotem.
     */
    public function consumeConnectState(string $candidate, ?int $now = null): bool
    {
        $expected = (string) $this->state->get(self::CONNECT, '');
        $until = (int) $this->state->get(self::CONNECT_UNTIL, 0);
        $this->state->remove(self::CONNECT, self::CONNECT_UNTIL);

        if ('' === $expected || '' === $candidate || ($now ?? time()) > $until) {
            return false;
        }

        return hash_equals($expected, $candidate);
    }

    /** Znacznik ostatniego autoryzowanego odpytania, zapisywany najwyżej raz na minutę. */
    public function touchLastPoll(): void
    {
        $last = (int) $this->state->get(self::LAST_POLL, 0);
        if (time() - $last >= 60) {
            $this->state->set([self::LAST_POLL => time()]);
        }
    }

    public function lastPollAt(): ?int
    {
        $last = (int) $this->state->get(self::LAST_POLL, 0);

        return $last > 0 ? $last : null;
    }

    private static function random(): string
    {
        return bin2hex(random_bytes(16));
    }
}
