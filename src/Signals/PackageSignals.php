<?php

declare(strict_types=1);

namespace Calmfox\WatchBundle\Signals;

use Calmfox\WatchBundle\Core\AdminFingerprint;
use Calmfox\WatchBundle\Core\SecretManager;

/**
 * Skład WŁĄCZONYCH rozszerzeń sklepu, czyli bundli zarejestrowanych w
 * config/bundles.php. To jest sylisowy odpowiednik listy aktywnych wtyczek
 * WordPressa: pakiet leżący w vendorze, ale zdjęty
 * z bundles.php, nie działa i nie ma prawa liczyć się jako włączony.
 *
 * Sygnały jadą w sekcji `health`, odpytywanej co minutę, a nie w `security`
 * czytanej raz na dobę: wyłączenie rozszerzenia zabezpieczającego to drugi
 * klasyczny krok po przejęciu panelu i wolimy wiedzieć o nim w ciągu minut.
 *
 * Nazwy wysyłamy świadomie i mówimy o tym wprost w README: bez nich zdarzenie
 * brzmiałoby „coś się zmieniło", a jedyna sensowna reakcja („sprawdźcie, czy
 * to Wasza zmiana") wymaga wiedzy, KTÓRE rozszerzenie zniknęło. Ceną jest to,
 * że kto zdobędzie sekretny adres kontrolny, zobaczy listę bundli. Ten sam
 * adres i tak wydaje wersję platformy, wersję PHP i historię aktualizacji
 * z nazwami pakietów.
 *
 * Pola `autoUpdates` NIE wysyłamy i to nie jest przeoczenie: Sylius nie ma
 * automatycznych aktualizacji, a nadanie temu polu jakiejkolwiek wartości
 * znaczyłoby „sprawdzone", zamiast „nie ma czego sprawdzać".
 */
final class PackageSignals
{
    /** Tyle nazw przyjmuje hub. Odcisk liczymy z CAŁEJ listy, więc zmiana poza setką też się wykryje. */
    private const NAMES_LIMIT = 100;

    /** Hub przycina dłuższe nazwy, więc przycinamy je sami, żeby odcisk zgadzał się z listą. */
    private const NAME_LENGTH = 80;

    /** @param array<string, class-string> $bundles zawartość %kernel.bundles% */
    public function __construct(
        private readonly array $bundles,
        private readonly SecretManager $secrets,
    ) {
    }

    /** @return array{pluginCount: int, pluginsFingerprint: string, activePlugins: list<string>} */
    public function signals(): array
    {
        $names = $this->names();

        return [
            'pluginCount' => \count($names),
            // Ta sama formuła co przy kontach administracyjnych (HMAC z sekretu
            // instalacji, 32 znaki hex). Klasa nazywa się AdminFingerprint, bo
            // tam powstała; powielanie formuły dałoby dwa miejsca do rozjechania.
            'pluginsFingerprint' => AdminFingerprint::of($names, $this->secrets->secret()),
            'activePlugins' => \array_slice($names, 0, self::NAMES_LIMIT),
        ];
    }

    /** @return list<string> posortowane nazwy bundli, bez duplikatów */
    private function names(): array
    {
        $names = [];
        foreach (array_keys($this->bundles) as $name) {
            $name = trim(mb_substr((string) $name, 0, self::NAME_LENGTH));
            if ('' !== $name) {
                $names[$name] = true;
            }
        }
        $names = array_keys($names);
        sort($names);

        return $names;
    }
}
