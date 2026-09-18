<?php

declare(strict_types=1);

namespace Calmfox\WatchBundle\Controller;

use Calmfox\WatchBundle\CalmfoxWatchBundle;
use Calmfox\WatchBundle\Core\SecretManager;
use Calmfox\WatchBundle\Core\StateStore;
use Calmfox\WatchBundle\Core\StatusSummary;
use Calmfox\WatchBundle\Core\ScoreRing;
use Calmfox\WatchBundle\History\UpdateHistory;
use Calmfox\WatchBundle\Score\ScoreProvider;
use Calmfox\WatchBundle\Hub\HubClient;
use Calmfox\WatchBundle\Payload\PayloadProvider;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

/**
 * Ekran Calmfox Watch w panelu administracyjnym Syliusa. Dwie drogi startu
 * (aktywacja pakietu Free albo połączenie kluczem z panelu), a po połączeniu
 * podgląd stanu usług, bezpieczeństwa i historii zmian.
 *
 * Świadomie nie dziedziczymy po AbstractController: pakiet ma działać także
 * w projektach bez automatycznego wiązania usług, więc wszystko, czego
 * potrzebujemy, wstrzykujemy wprost.
 */
final class AdminController
{
    private const CSRF_ID = 'calmfox_watch';

    public function __construct(
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $router,
        private readonly CsrfTokenManagerInterface $csrf,
        private readonly AuthorizationCheckerInterface $authorization,
        private readonly HubClient $hub,
        private readonly PayloadProvider $payloads,
        private readonly SecretManager $secrets,
        private readonly StateStore $state,
        private readonly UpdateHistory $history,
        private readonly ScoreProvider $score,
        private readonly string $layout,
    ) {
    }

    public function index(Request $request): Response
    {
        $this->assertGranted();

        // Powrót z panelu przychodzi zwykłym GET-em na ten adres: ?cw_token=…&cw_state=….
        // Te same nazwy parametrów co we wtyczce WordPressa i w module Magento, bo panel
        // jest jeden i nie ma powodu, żeby każda platforma miała własny dialekt.
        if ($request->query->has('cw_token') || $request->query->has('cw_state')) {
            return $this->finishConnect($request);
        }

        $connected = (bool) $this->state->get('connected', false);
        $health = $this->payloads->payload(PayloadProvider::SECTION_HEALTH);
        $security = $this->payloads->payload(PayloadProvider::SECTION_SECURITY);

        return new Response($this->twig->render('@CalmfoxWatch/admin.html.twig', [
            'calmfox_layout' => $this->layout,
            'version' => CalmfoxWatchBundle::VERSION,
            'connected' => $connected,
            'state' => $this->state->all(),
            'health_url' => $this->hub->healthUrl(),
            'api_url' => $this->hub->apiUrl(),
            'panel_url' => $this->hub->panelUrl(),
            'panel_host' => (string) parse_url($this->hub->panelUrl(), \PHP_URL_HOST),
            // Przycisk łączenia przez panel pokazujemy tylko wtedy, gdy mamy dokąd
            // wrócić z kluczem. Bez tego zostaje wklejenie klucza ręcznie.
            'connect_available' => null !== $this->connectReturnUrl(),
            'domain' => $this->hub->domain(),
            'health' => $health,
            'security' => $security,
            'history' => \array_slice($this->history->all(), 0, 10),
            // Ocena z panelu. `null` znaczy „nie wiemy" (brak połączenia, próg Free,
            // hub jeszcze nie policzył) i szablon chowa wtedy pierścień w całości.
            'score' => $this->score->get(),
            'loopback' => $this->hub->loopbackCheck(),
            'last_poll_at' => $this->secrets->lastPollAt(),
            'disk_quota_gb' => (float) $this->state->get('diskQuotaGb', 0),
            'plan' => $this->plan(),
            'is_free' => self::isFree($this->plan()),
            'plan_url' => $this->hub->panelLink('/app/plan'),
            'csrf_token' => $this->csrf->getToken(self::CSRF_ID)->getValue(),
        ]));
    }

    public function register(Request $request): Response
    {
        $this->assertGranted();
        $this->assertCsrf($request);

        $email = trim((string) $request->request->get('email', ''));
        if (!filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            return $this->back($request, 'error', 'Podaj poprawny adres e-mail.');
        }
        if (!$request->request->getBoolean('consent')) {
            return $this->back($request, 'error', 'Do aktywacji potrzebna jest zgoda na przekazanie adresu e-mail i domeny do Calmfox.');
        }

        $result = $this->hub->register($email);

        return $this->back($request, $result['ok'] ? 'success' : 'error', $result['message']);
    }

    /**
     * Kafelek na pulpicie panelu administracyjnego. Renderowany podzapytaniem
     * z szablonu wpiętego w pulpit (zdarzenie szablonu w Syliusie 1.x, hook
     * w 2.x), więc jest zwykłą akcją z własnym uprawnieniem, a nie fragmentem
     * doklejanym do cudzego szablonu.
     *
     * Sens jest jeden: człowiek, który wchodzi do sklepu po czymś innym, ma
     * zobaczyć awarię bez wchodzenia na nasz ekran. Dlatego kafelek pokazuje
     * najwyżej trzy najpilniejsze rzeczy i mówi, ile jest pozostałych.
     */
    public function widget(Request $request): Response
    {
        $this->assertGranted();

        $connected = (bool) $this->state->get('connected', false);
        $health = $connected ? $this->payloads->payload(PayloadProvider::SECTION_HEALTH) : [];
        $security = $connected ? $this->payloads->payload(PayloadProvider::SECTION_SECURITY) : [];

        return new Response($this->twig->render('@CalmfoxWatch/widget.html.twig', [
            'version' => CalmfoxWatchBundle::VERSION,
            'connected' => $connected,
            'summary' => StatusSummary::of($health, $security),
            // Parametry monitoringu, czyli to, co pilnujemy w tym sklepie. Kafelek
            // pokazuje je nawet wtedy, gdy wszystko działa: bez tej listy „nic nie
            // wymaga uwagi" nie mówi, CZEGO właściwie nic nie wymaga.
            'checks' => \is_array($health['checks'] ?? null) ? $health['checks'] : [],
            'score' => $connected ? $this->score->get() : null,
            'screen_url' => $this->router->generate('calmfox_watch_admin'),
            'last_poll_at' => $this->secrets->lastPollAt(),
            'plan' => $this->plan(),
            'is_free' => self::isFree($this->plan()),
            'plan_url' => $this->hub->panelLink('/app/plan'),
            // Drugie CTA kafelka: ten sklep w panelu, nie sam cennik.
            'panel_site_url' => $this->hub->panelLink('/app/dashboard'),
            // Pierścień zastępczy na miejsce oceny, której na Free nie ma. Liczymy go tu,
            // a nie w szablonie: geometria należy do rdzenia, widok tylko rysuje.
            'placeholder_ring' => ScoreRing::placeholder(),
        ]));
    }

    /**
     * Łączenie przez panel, ta sama droga co we wtyczce WordPressa: wychodzimy
     * do watch.calmfox.net, tam człowiek loguje się albo zakłada konto i wybiera
     * organizację, a wracamy tutaj z kluczem instalacyjnym i parujemy się sami.
     * Strona nie musi wcześniej istnieć w panelu.
     *
     * Znacznik jednorazowy powstaje PRZED wyjściem i jest jedynym dowodem, że
     * powrót jest odpowiedzią na to konkretne kliknięcie. Bez niego wystarczyłoby
     * podrzucić administratorowi link z cudzym kluczem, żeby podpiąć ten sklep
     * pod obce konto.
     */
    public function connect(Request $request): Response
    {
        $this->assertGranted();
        $this->assertCsrf($request);

        $return = $this->connectReturnUrl();
        if (null === $return) {
            return $this->back($request, 'error', 'Nie potrafię zbudować adresu powrotnego na domenie sklepu, więc panel odrzuciłby połączenie. Połącz kluczem instalacyjnym z ekranu Integracje.');
        }

        $query = http_build_query([
            'domain' => $this->hub->domain(),
            'return' => $return,
            'state' => $this->secrets->makeConnectState(),
        ]);

        // Cel jest na naszym panelu, nie w tym sklepie, więc świadomie wychodzimy
        // poza aplikację. Klucz wraca dopiero w odpowiedzi, tu nie ma go jeszcze
        // czego chronić.
        return new RedirectResponse($this->hub->panelUrl().'/polacz/sylius?'.$query);
    }

    /**
     * Powrót z panelu. Kolejność sprawdzeń nie jest przypadkowa: najpierw znacznik
     * (jednorazowy, zużywany niezależnie od wyniku), potem format klucza, dopiero
     * na końcu rozmowa z hubem. Klucz jest jawny, więc to znacznik decyduje, czy
     * ten powrót w ogóle nas dotyczy.
     *
     * Wracamy przekierowaniem na czysty adres ekranu, żeby klucz nie został
     * w pasku adresu, w historii przeglądarki ani w logu serwera.
     */
    private function finishConnect(Request $request): RedirectResponse
    {
        $state = trim((string) $request->query->get('cw_state', ''));
        $token = trim((string) $request->query->get('cw_token', ''));

        if (!$this->secrets->consumeConnectState($state)) {
            return $this->back($request, 'error', 'Znacznik połączenia wygasł albo się nie zgadza. Kliknij „Połącz przez panel" jeszcze raz.');
        }
        if (1 !== preg_match('/^(fxp_live_)?[a-f0-9]{16,32}$/', $token)) {
            return $this->back($request, 'error', 'Klucz z panelu ma nieoczekiwany format. Spróbuj połączyć jeszcze raz.');
        }

        $result = $this->hub->pair($token);

        return $this->back($request, $result['ok'] ? 'success' : 'error', $result['message']);
    }

    /**
     * Adres powrotny dla panelu: ten ekran, w postaci bezwzględnej. Panel wpuszcza
     * wyłącznie adres NA DOMENIE łączonego sklepu i niosący znacznik naszej trasy
     * administracyjnej, bo wraca nim klucz instalacyjny. Gdy host ekranu jest inny
     * niż domena zgłaszana hubowi (panel na osobnej domenie), połączenie tą drogą
     * nie ma prawa się udać i zamiast wysyłać człowieka po komunikat błędu,
     * chowamy przycisk.
     */
    private function connectReturnUrl(): ?string
    {
        try {
            $url = $this->router->generate('calmfox_watch_admin', [], UrlGeneratorInterface::ABSOLUTE_URL);
        } catch (\Throwable) {
            return null;
        }

        $host = mb_strtolower((string) parse_url($url, \PHP_URL_HOST));
        $scheme = mb_strtolower((string) parse_url($url, \PHP_URL_SCHEME));
        $domain = mb_strtolower($this->hub->domain());
        if ('' === $host || !\in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        return preg_replace('/^www\./', '', $host) === preg_replace('/^www\./', '', $domain) ? $url : null;
    }

    public function pair(Request $request): Response
    {
        $this->assertGranted();
        $this->assertCsrf($request);

        $token = trim((string) $request->request->get('token', ''));
        if (1 !== preg_match('/^(fxp_live_)?[a-f0-9]{16,32}$/', $token)) {
            return $this->back($request, 'error', 'Klucz ma inny format niż fxp_live_… Skopiuj go z ekranu Integracje w panelu.');
        }

        $result = $this->hub->pair($token);

        return $this->back($request, $result['ok'] ? 'success' : 'error', $result['message']);
    }

    /** Wymiana sekretu: nowy adres kontrolny plus przepięcie huba (poprzedni działa jeszcze kwadrans). */
    public function rotate(Request $request): Response
    {
        $this->assertGranted();
        $this->assertCsrf($request);

        $this->secrets->rotate();
        $result = $this->hub->pair();
        $this->score->forget();

        return $this->back($request, $result['ok'] ? 'success' : 'error', $result['ok']
            ? 'Klucz zabezpieczający wymieniony. Monitoring korzysta już z nowego adresu.'
            : sprintf('Klucz wymieniono w sklepie, ale nie udało się zaktualizować go w panelu: %s Poprzedni klucz działa jeszcze 15 minut, spróbuj ponownie przyciskiem „Połącz ponownie".', $result['message']));
    }

    public function quota(Request $request): Response
    {
        $this->assertGranted();
        $this->assertCsrf($request);

        $raw = str_replace(',', '.', (string) $request->request->get('quota', ''));
        $quota = '' === trim($raw) ? 0.0 : (float) $raw;
        if ($quota < 0 || $quota > 100000) {
            return $this->back($request, 'error', 'Podaj limit w gigabajtach, liczbę z zakresu od 0 do 100000 (0 znaczy „nie znam limitu").');
        }

        $this->state->set(['diskQuotaGb' => $quota]);
        $this->payloads->forget();

        return $this->back($request, 'success', $quota > 0
            ? sprintf('Zapisane. Pilnujemy zajętości względem %s GB.', rtrim(rtrim(number_format($quota, 2, ',', ' '), '0'), ','))
            : 'Wyczyszczone. Wracamy do informowania, że limit konta nie jest znany.');
    }

    public function disconnect(Request $request): Response
    {
        $this->assertGranted();
        $this->assertCsrf($request);

        $result = $this->hub->disconnect();
        $this->score->forget();

        return $this->back($request, 'success', $result['ok']
            ? $result['message']
            : sprintf('Monitoring wstrzymany w sklepie, ale panel nie potwierdził rozłączenia: %s', $result['message']));
    }

    /** Pakiet znany z chwili połączenia. Źródłem prawdy jest panel i tak to nazywamy na ekranie. */
    private function plan(): string
    {
        return (string) $this->state->get('plan', '');
    }

    /**
     * Brak pakietu traktujemy jak Free: strona dodana z pakietu wchodzi właśnie na Free,
     * a starsze instalacje mogą nie mieć tego pola w stanie. Pomyłka w tę stronę pokazuje
     * o jedno zaproszenie do pakietu za dużo, w drugą ukryłaby je przed tym, kto płaci zero.
     */
    private static function isFree(string $plan): bool
    {
        return '' === $plan || 'free' === mb_strtolower($plan);
    }

    private function assertGranted(): void
    {
        // Firewall panelu jest pierwszą bramką, ta jest drugą: nawet gdyby ktoś
        // wystawił trasę poza /admin, ekran nie odda niczego bez uprawnień.
        if (!$this->authorization->isGranted('ROLE_ADMINISTRATION_ACCESS')) {
            throw new AccessDeniedException('Ekran Calmfox Watch wymaga dostępu do panelu administracyjnego.');
        }
    }

    private function assertCsrf(Request $request): void
    {
        if (!$this->csrf->isTokenValid(new CsrfToken(self::CSRF_ID, (string) $request->request->get('_token', '')))) {
            throw new AccessDeniedException('Nieprawidłowy znacznik formularza.');
        }
    }

    private function back(Request $request, string $type, string $message): RedirectResponse
    {
        $session = $request->hasSession() ? $request->getSession() : null;
        if (null !== $session && method_exists($session, 'getFlashBag')) {
            // Własne klucze, żeby stopka panelu Syliusa nie wyświetliła tej samej
            // wiadomości drugi raz: ekran renderuje je sam.
            $session->getFlashBag()->add('success' === $type ? 'calmfox_success' : 'calmfox_error', $message);
        }

        return new RedirectResponse($this->router->generate('calmfox_watch_admin'));
    }
}
