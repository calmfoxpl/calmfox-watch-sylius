<?php

declare(strict_types=1);

namespace Calmfox\WatchBundle\Hub;

use Calmfox\WatchBundle\Core\SecretManager;
use Calmfox\WatchBundle\Core\StateStore;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Rozmowa z API Calmfox Watch. Podczas rejestracji i parowania hub wykonuje
 * challenge: pobiera NASZ adres kontrolny na domenie sklepu i oczekuje echa
 * znacznika. To dlatego znacznik zapisujemy przed wysłaniem żądania, a limit
 * czasu jest długi (hub w trakcie obsługi puka do nas).
 */
final class HubClient
{
    public const CMS = 'Sylius';

    /** Hub w trakcie obsługi żądania odpytuje nasz adres kontrolny. */
    private const PAIRING_TIMEOUT = 25;
    private const DISCONNECT_TIMEOUT = 8;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly SecretManager $secrets,
        private readonly StateStore $state,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly string $apiUrl,
        private readonly ?string $siteUrl = null,
    ) {
    }

    /**
     * Aktywacja pakietu Free wprost ze sklepu: konto, strona i monitoring
     * po stronie huba, link logowania na podany adres.
     *
     * @return array{ok: bool, message: string, data: array<string, mixed>}
     */
    public function register(string $email): array
    {
        $result = $this->call('/api/public/plugin/register', [
            'domain' => $this->domain(),
            'email' => $email,
            'healthUrl' => $this->healthUrl(),
            'nonce' => $this->secrets->makePairingNonce(),
            'cms' => self::CMS,
        ], 201);
        $this->secrets->clearPairingNonce();

        if ($result['ok']) {
            $this->savePaired($result['data']);
            $result['message'] = 'Konto Free jest aktywne. Sprawdź skrzynkę, wysłaliśmy link logowania do panelu.';
        }

        return $result;
    }

    /**
     * Parowanie z istniejącą stroną kluczem instalacyjnym z ekranu Integracje.
     * Tą samą drogą idzie wymiana sekretu: hub zapamiętuje nowy adres kontrolny.
     *
     * @return array{ok: bool, message: string, data: array<string, mixed>}
     */
    public function pair(string $token = ''): array
    {
        $token = '' !== $token ? $token : (string) $this->state->get('installToken', '');
        if ('' === $token) {
            return ['ok' => false, 'message' => 'Brak klucza instalacyjnego. Skopiuj go z ekranu Integracje w panelu.', 'data' => []];
        }

        $result = $this->call('/api/public/plugin/pair', [
            'token' => $token,
            'healthUrl' => $this->healthUrl(),
            'nonce' => $this->secrets->makePairingNonce(),
            'cms' => self::CMS,
        ], 200);
        $this->secrets->clearPairingNonce();

        if ($result['ok']) {
            $this->savePaired($result['data']);
            $result['message'] = 'Połączono z Calmfox Watch. Monitoring wnętrza sklepu działa.';
        }

        return $result;
    }

    /**
     * Rozłączenie. Sam klucz instalacyjny nie wystarcza (jest jawny), więc hub
     * żąda też sekretu z adresu kontrolnego, który zna wyłącznie ta instalacja.
     * Robimy to najlepszym staraniem: przy braku sieci hub i tak zauważy
     * milczący adres.
     */
    public function disconnect(): array
    {
        $token = (string) $this->state->get('installToken', '');
        if ('' === $token) {
            return ['ok' => false, 'message' => 'Ten sklep nie jest połączony.', 'data' => []];
        }

        $result = $this->call('/api/public/plugin/disconnect', [
            'token' => $token,
            'key' => $this->secrets->secret(),
        ], 204);

        $this->state->set(['connected' => false, 'disconnectedAt' => gmdate('c')]);
        if ($result['ok']) {
            $result['message'] = 'Połączenie zakończone. Monitoring wnętrza sklepu został wstrzymany, klucz zostaje zapisany, więc ponowne połączenie zajmie jedno kliknięcie.';
        }

        return $result;
    }

    /**
     * Samokontrola adresu kontrolnego pętlą zwrotną. Wyłapuje zapory i reguły
     * dostępu, które blokują naszą ścieżkę, zanim człowiek utknie na parowaniu.
     * Uczciwie: to test od środka serwera, dostęp z zewnątrz ostatecznie
     * potwierdza dopiero challenge huba.
     *
     * @return array{ok: bool, message: string}
     */
    public function loopbackCheck(): array
    {
        try {
            $response = $this->httpClient->request('GET', $this->healthUrl(), [
                'timeout' => 5,
                'max_redirects' => 0,
                'verify_peer' => false,
                'verify_host' => false,
            ]);
            $code = $response->getStatusCode();
            $body = json_decode($response->getContent(false), true);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        if (\in_array($code, [200, 503], true) && \is_array($body) && isset($body['status'])) {
            return ['ok' => true, 'message' => ''];
        }

        return ['ok' => false, 'message' => sprintf('adres kontrolny odpowiada kodem %d albo obcym formatem', $code)];
    }

    /** Pełny sekretny adres kontrolny. To on jest zapamiętywany po stronie huba. */
    public function healthUrl(): string
    {
        $key = $this->secrets->secret();
        if (null !== $this->siteUrl && '' !== $this->siteUrl) {
            return $this->siteUrl.$this->urlGenerator->generate('calmfox_watch_health', ['key' => $key]);
        }

        return $this->urlGenerator->generate('calmfox_watch_health', ['key' => $key], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    public function domain(): string
    {
        return (string) parse_url($this->healthUrl(), \PHP_URL_HOST);
    }

    public function apiUrl(): string
    {
        return $this->apiUrl;
    }

    /**
     * Adres panelu, do którego odsyłamy administratora przy łączeniu i przy
     * podglądzie sklepu. Zapisujemy go przy parowaniu, bo instalacja testowa
     * klienta bywa pod innym adresem niż nasz panel produkcyjny.
     *
     * Przeprowadzka panelu na watch.calmfox.net: adres zapisany przy parowaniu
     * przestawiamy na nowy, bo stary host oddaje już tylko 308. Adres wpisany
     * ręcznie zostaje nietknięty.
     */
    public function panelUrl(): string
    {
        $stored = rtrim((string) $this->state->get('panelUrl', ''), '/');
        if ('' === $stored || 'https://watch.calmfox.pl' === $stored) {
            $stored = '';
        }

        return '' !== $stored ? $stored : $this->apiUrl;
    }

    /**
     * Adres ekranu w panelu dla TEJ strony. Bez identyfikatora strony panel otworzy
     * się na ostatnio oglądanej, czyli u agencji na cudzej: stąd parametr `site`,
     * ten sam, którego używa wtyczka WordPressa.
     */
    public function panelLink(string $path = '/app/dashboard'): string
    {
        $url = $this->panelUrl().$path;
        $siteId = (string) $this->state->get('siteId', '');

        return '' !== $siteId ? $url.'?site='.rawurlencode($siteId) : $url;
    }

    /** @param array<string, mixed> $data */
    private function savePaired(array $data): void
    {
        $this->state->set([
            'connected' => true,
            'installToken' => (string) ($data['installToken'] ?? $this->state->get('installToken', '')),
            'siteId' => (string) ($data['siteId'] ?? ''),
            'plan' => (string) ($data['plan'] ?? ''),
            'panelUrl' => '' !== (string) ($data['panelUrl'] ?? '') ? (string) $data['panelUrl'] : (string) $this->state->get('panelUrl', 'https://watch.calmfox.net'),
            'pairedAt' => gmdate('c'),
        ]);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array{ok: bool, message: string, data: array<string, mixed>}
     */
    private function call(string $path, array $body, int $expected): array
    {
        try {
            $response = $this->httpClient->request('POST', $this->apiUrl.$path, [
                'timeout' => 204 === $expected ? self::DISCONNECT_TIMEOUT : self::PAIRING_TIMEOUT,
                'max_duration' => (204 === $expected ? self::DISCONNECT_TIMEOUT : self::PAIRING_TIMEOUT) + 2,
                'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
                'body' => (string) json_encode($body, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES),
            ]);
            $code = $response->getStatusCode();
            $raw = $response->getContent(false);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => sprintf('Nie udało się połączyć z Calmfox Watch: %s', $e->getMessage()), 'data' => []];
        }

        $data = json_decode($raw, true);
        $data = \is_array($data) ? $data : [];

        if ($code === $expected) {
            return ['ok' => true, 'message' => '', 'data' => $data];
        }

        foreach (['detail', 'message', 'error'] as $key) {
            if (\is_string($data[$key] ?? null) && '' !== $data[$key]) {
                return ['ok' => false, 'message' => $data[$key], 'data' => []];
            }
        }

        return ['ok' => false, 'message' => sprintf('Serwer Calmfox Watch odpowiedział kodem %d.', $code), 'data' => []];
    }
}
