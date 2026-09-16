<?php

declare(strict_types=1);

namespace Calmfox\WatchBundle\Check\Security;

use Calmfox\WatchBundle\Check\HealthCheckInterface;
use Calmfox\WatchBundle\Core\CheckResult;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\RequestContext;

/**
 * Szyfrowanie połączenia. Kolejność źródeł wynika z jednej obawy: fałszywej
 * awarii. Za pośrednikiem sieciowym bez ustawionych zaufanych adresów żądanie
 * wygląda na nieszyfrowane, a `fail` w tej sekcji trafia do raportu i mówi
 * klientowi, że jego sklep nie ma certyfikatu, choć ma.
 *
 * Dlatego: 1) adres sklepu z konfiguracji, jeśli podany, 2) bieżące żądanie
 * (razem z nagłówkiem X-Forwarded-Proto, bo adres kontrolny i tak wymaga
 * sekretu, więc nikt obcy nie podrzuci nam tu nagłówka), 3) kontekst routera.
 * Dopiero gdy wszystkie mówią http, jest to realny problem.
 */
final class HttpsCheck implements HealthCheckInterface
{
    public function __construct(
        private readonly ?string $siteUrl = null,
        private readonly ?RequestStack $requestStack = null,
        private readonly ?RequestContext $context = null,
    ) {
    }

    public function run(): ?CheckResult
    {
        // Bez polecenia: podmiana adresów sklepu jednym wywołaniem z literówką
        // w domenie potrafi odciąć dostęp do panelu.
        return CheckResult::of($this->isSecure() ? CheckResult::OK : CheckResult::FAIL, 'https', 'Szyfrowanie HTTPS', $this->isSecure()
            ? null
            : 'Sklep działa po nieszyfrowanym połączeniu. Dane logowania i dane z formularzy przesyłane są otwartym tekstem, a przeglądarki ostrzegają kupujących.',
            fix: 'Włącz certyfikat u hostingodawcy, dodaj stałe przekierowanie z http na https i ustaw adres kanału w panelu sklepu na wersję z https.');
    }

    private function isSecure(): bool
    {
        if (null !== $this->siteUrl && '' !== $this->siteUrl) {
            return str_starts_with(mb_strtolower($this->siteUrl), 'https://');
        }

        $request = $this->requestStack?->getCurrentRequest();
        if (null !== $request) {
            return $request->isSecure() || 'https' === mb_strtolower((string) $request->headers->get('X-Forwarded-Proto', ''));
        }

        return 'https' === mb_strtolower((string) $this->context?->getScheme());
    }
}
