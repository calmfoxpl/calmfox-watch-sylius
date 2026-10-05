<?php

declare(strict_types=1);

namespace Calmfox\WatchBundle\Menu;

/**
 * Pozycja w menu panelu administracyjnego (zdarzenie sylius.menu.admin.main), w grupie
 * „Usługi Calmfox", którą dzielą wszystkie wtyczki Calmfox. Podział jest prosty: to, co należy do
 * jednej metody dostawy albo płatności, zostaje przy tej metodzie, a usługi ustawiane raz dla
 * całego sklepu (konta, klucze, połączenia, monitoring) mają własne strony w jednej grupie, tuż za
 * „Konfiguracją". Tam ich szuka ktoś, kto chce coś w sklepie ustawić albo sprawdzić.
 *
 * Grupę rozpoznajemy po kluczu `calmfox`: tworzy ją ta wtyczka, która buduje menu pierwsza,
 * a pozostałe tylko do niej dopisują. Dlatego klucz, etykieta i ikona muszą być takie same we
 * wszystkich wtyczkach Calmfox.
 *
 * Typ zdarzenia świadomie nie jest zadeklarowany: Sylius 1.x i 2.x wołają ten
 * sam identyfikator zdarzenia, ale klasa bywa w innej przestrzeni nazw, a jedna
 * pozycja w menu nie jest wystarczającym powodem, żeby wydawać dwie wersje
 * pakietu. Gdy struktura menu się nie zgadza, po prostu nic nie robimy: ekran
 * zostaje dostępny pod swoim adresem i z poleceń CLI.
 */
final class AdminMenuListener
{
    public const SECTION = 'calmfox';

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
        if (!\is_object($menu) || !method_exists($menu, 'addChild') || !method_exists($menu, 'getChild')) {
            return;
        }

        $section = $menu->getChild(self::SECTION) ?? $this->createSection($menu);

        // Sekcja z pozycjami, a nie sam odnośnik w korzeniu: w Syliusie 1.x
        // menu główne renderuje dzieci korzenia jako nagłówki grup i pozycja bez
        // dziecka byłaby nagłówkiem, w który nie da się kliknąć.
        $item = $section->addChild('calmfox_watch_overview', ['route' => 'calmfox_watch_admin']);
        $item->setLabel('calmfox_watch.menu.admin');
        if (method_exists($item, 'setLabelAttribute')) {
            $item->setLabelAttribute('icon', $this->icon('heartbeat'));
        }
    }

    private function createSection(object $menu): object
    {
        $section = $menu->addChild(self::SECTION);
        $section->setLabel('calmfox.menu.section');
        if (method_exists($section, 'setLabelAttribute')) {
            $section->setLabelAttribute('icon', $this->icon('plug-connected', 'plug'));
        }
        $this->placeAfterConfiguration($menu);

        return $section;
    }

    /**
     * Nazwy ikon różnią się między wersjami: 1.x rysuje ikony Semantic UI,
     * 2.x zestaw Tabler z przedrostkiem. Rozpoznajemy po pakiecie, który
     * istnieje wyłącznie w 2.x, bo to pewniejsze niż zgadywanie po numerze
     * wersji z Composera. Zła nazwa ikony to najwyżej brak obrazka, więc
     * to jest kosmetyka, a nie warunek działania.
     */
    private function icon(string $tabler, ?string $semantic = null): string
    {
        return isset($this->bundles['SyliusTwigHooksBundle']) ? 'tabler:' . $tabler : ($semantic ?? $tabler);
    }

    /**
     * Nowa grupa ląduje domyślnie na końcu menu. Przesuwamy ją zaraz za
     * „Konfigurację", bo tam jest ktoś, kto szuka ustawień. Cała operacja jest
     * opcjonalna: gdy menu nie umie się przestawiać, nie ma „Konfiguracji" albo
     * cokolwiek pójdzie nie tak, zostaje kolejność domyślna.
     */
    private function placeAfterConfiguration(object $menu): void
    {
        if (!method_exists($menu, 'reorderChildren') || !method_exists($menu, 'getChildren')) {
            return;
        }

        try {
            $keys = array_values(array_filter(array_map('strval', array_keys($menu->getChildren())), static fn (string $key): bool => self::SECTION !== $key));
            $configuration = array_search('configuration', $keys, true);
            if (false === $configuration) {
                return;
            }
            array_splice($keys, $configuration + 1, 0, [self::SECTION]);
            $menu->reorderChildren($keys);
        } catch (\Throwable) {
            // Kolejność jest wygodą, nie funkcją. Nie ma za co wywracać panelu.
        }
    }
}
