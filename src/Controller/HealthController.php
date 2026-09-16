<?php

declare(strict_types=1);

namespace Calmfox\WatchBundle\Controller;

use Calmfox\WatchBundle\Core\ResponseSigner;
use Calmfox\WatchBundle\Core\SecretManager;
use Calmfox\WatchBundle\Payload\PayloadProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sekretny adres kontrolny: GET /calmfox-watch/health?key=<32 znaki hex>.
 * Kontrakt z hubem: 200 = ok albo warn, 503 = fail, nic innego. Bez ważnego
 * klucza suche 403. To kanał danych, nie podstrona, więc odpowiedź nie trafia
 * do pamięci podręcznej ani do wyszukiwarek.
 *
 * Treść składamy TUTAJ, własnym json_encode, i oddajemy gotowe bajty. Gdyby
 * robił to framework (JsonResponse i modyfikacje po drodze), podpis liczyłby
 * się nad czymś innym niż to, co wyjdzie na łącze.
 */
final class HealthController
{
    public function __construct(
        private readonly SecretManager $secrets,
        private readonly PayloadProvider $payloads,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        if (!$this->secrets->accepts((string) $request->query->get('key', ''))) {
            return $this->respond(['error' => 'forbidden'], Response::HTTP_FORBIDDEN);
        }

        $this->secrets->touchLastPoll();

        $payload = $this->payloads->payload(
            PayloadProvider::SECTION_SECURITY === $request->query->get('section') ? PayloadProvider::SECTION_SECURITY : PayloadProvider::SECTION_HEALTH
        );

        // Echo znacznika parowania czytamy na żywo, POZA pamięcią podręczną:
        // challenge huba przychodzi zaraz po zapisaniu znacznika i trafiłby
        // na payload sprzed jego powstania.
        $pairing = $this->secrets->pairingNonce();
        if ('' !== $pairing) {
            $payload['pairing'] = $pairing;
        }

        $status = 'fail' === ($payload['status'] ?? '') ? Response::HTTP_SERVICE_UNAVAILABLE : Response::HTTP_OK;

        return $this->respond($payload, $status, (string) $request->query->get('nonce', ''));
    }

    /** @param array<string, mixed> $payload */
    private function respond(array $payload, int $status, string $proofNonce = ''): Response
    {
        $body = ResponseSigner::encode($payload);

        // Symfony dopisuje do Cache-Control „private" i porządkuje wartości
        // alfabetycznie. To nie jest odejście od kontraktu: „no-store" i
        // „max-age=0" zostają, a „private" jest tu prawdą (dane jednej instalacji).
        $response = new Response($body, $status, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-store, max-age=0',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);

        if ('' !== $proofNonce) {
            $generatedAt = ResponseSigner::generatedAt();
            $response->headers->set(ResponseSigner::GENERATED_HEADER, $generatedAt);
            $response->headers->set(ResponseSigner::PROOF_HEADER, ResponseSigner::proof($proofNonce, $generatedAt, $body, $this->secrets->secret()));
        }

        return $response;
    }
}
