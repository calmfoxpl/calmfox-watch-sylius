<?php

declare(strict_types=1);

namespace Calmfox\WatchBundle\Menu;

/**
 * Pozycja w menu panelu administracyjnego (zdarzenie sylius.menu.admin.main).
 * Wchodzi do GŁÓWNEGO menu, a nie pod „Konfigurację": monitoring ma być widoczny
 * z każdego ekranu sklepu i jednym kliknięciem, bo jego wartość polega na tym,
 * że ktoś zauważy awarię, zanim zauważy ją klient. Schowany o dwa poziomy
 * w konfiguracji zaglądałby do niego wyłącznie ten, kto go szuka.
 *
 * Typ zdarzenia świadomie nie jest zadeklarowany: Sylius 1.x i 2.x wołają ten
 * sam identyfikator zdarzenia, ale klasa bywa w innej przestrzeni nazw, a jedna
 * pozycja w menu nie jest wystarczającym powodem, żeby wydawać dwie wersje
 * pakietu. Gdy struktura menu się nie zgadza, po prostu nic nie robimy: ekran
 * zostaje dostępny pod swoim adresem i z poleceń CLI.
 */
final class AdminMenuListener
{
    /** @param array<string, class-string> $bundles zawartość %kernel.bundles% */
    public function __construct(private readonly array $bundles = [])
    {
    }

    public function __invoke(object $event): void
    {
        if (!method_exists($event, 'getMenu')) {
            return;
        }

        $menu = $event->getMenu();
        if (!\is_object($menu) || !method_exists($menu, 'addChild')) {
            return;
        }

        $icon = $this->icon();

        $section = $menu->addChild('calmfox_watch');
        $section->setLabel('calmfox_watch.menu.admin');
        if (method_exists($section, 'setLabelAttribute')) {
            $section->setLabelAttribute('icon', $icon);
        }

        // Sekcja z jedną pozycją, a nie sam odnośnik w korzeniu: w Syliusie 1.x
        // menu główne renderuje dzieci korzenia jako nagłówki grup i pozycja bez
        // dziecka byłaby nagłówkiem, w który nie da się kliknąć.
        $item = $section->addChild('calmfox_watch_overview', ['route' => 'calmfox_watch_admin']);
        $item->setLabel('calmfox_watch.menu.overview');
        if (method_exists($item, 'setLabelAttribute')) {
            $item->setLabelAttribute('icon', $icon);
        }

        $this->moveUnderDashboard($menu);
    }

    /**
     * Nazwy ikon różnią się między wersjami: 1.x rysuje ikony Semantic UI,
     * 2.x zestaw Tabler z przedrostkiem. Rozpoznajemy po pakiecie, który
     * istnieje wyłącznie w 2.x, bo to pewniejsze niż zgadywanie po numerze
     * wersji z Composera. Zła nazwa ikony to najwyżej brak obrazka, więc
     * to jest kosmetyka, a nie warunek działania.
     */
    private function icon(): string
    {
        return isset($this->bundles['SyliusTwigHooksBundle']) ? 'tabler:heartbeat' : 'heartbeat';
    }

    /**
     * Nasza pozycja ląduje domyślnie na końcu menu, czyli pod administracją.
     * Przesuwamy ją zaraz za pulpit, bo tam patrzy człowiek wchodzący do sklepu.
     * Cała operacja jest opcjonalna: gdy menu nie umie się przestawiać albo
     * cokolwiek pójdzie nie tak, zostaje kolejność domyślna.
     */
    private function moveUnderDashboard(object $menu): void
    {
        if (!method_exists($menu, 'reorderChildren') || !method_exists($menu, 'getChildren')) {
            return;
        }

        try {
            $keys = array_values(array_filter(array_keys($menu->getChildren()), static fn (string $key): bool => 'calmfox_watch' !== $key));
            $dashboard = array_search('dashboard', $keys, true);
            array_splice($keys, false === $dashboard ? 0 : $dashboard + 1, 0, ['calmfox_watch']);
            $menu->reorderChildren($keys);
        } catch (\Throwable) {
            // Kolejność jest wygodą, nie funkcją. Nie ma za co wywracać panelu.
        }
    }
}
