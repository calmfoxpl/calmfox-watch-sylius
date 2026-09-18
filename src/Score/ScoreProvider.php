<?php

declare(strict_types=1);

namespace Calmfox\WatchBundle\Score;

use Calmfox\WatchBundle\Core\FileCache;
use Calmfox\WatchBundle\Core\ScoreRing;
use Calmfox\WatchBundle\Core\StateStore;
use Calmfox\WatchBundle\Hub\HubClient;

/**
 * Ocena kondycji na ekran i kafelek pakietu: pobrana z huba, zbuforowana, gotowa do rysowania.
 *
 * Odpowiednik `Calmfox_Watch_Score` z wtyczki WordPressa i trzyma się tych samych zasad:
 *
 * - `null` znaczy „NIE WIEMY" i sekcja oceny wtedy w ogóle się nie pokazuje. Pusta ramka
 *   z zerem kłamałaby o stanie sklepu, a zero jest w tej skali najgorszym wynikiem.
 * - Cisza huba NIE JEST oceną: zapamiętujemy ją na krótko (5 minut), żeby nie pukać co odsłonę
 *   pulpitu, ale nie na godzinę — bo hub wróci wcześniej niż za godzinę i ekran ma to zobaczyć.
 * - Ocena przelicza się w hubie raz na dobę, więc godzina w cache nie postarza jej zauważalnie,
 *   a ekran administracyjny nie czeka na sieć.
 */
final class ScoreProvider
{
    private const CACHE_KEY = 'score';

    /** Udana odpowiedź: ocena i tak przelicza się raz na dobę. */
    private const TTL = 3600;

    /** Hub milczy albo odmawia: krótka cisza, żeby nie pukać co odsłonę ekranu. */
    private const TTL_SILENCE = 300;

    public function __construct(
        private readonly HubClient $hub,
        private readonly StateStore $state,
        private readonly FileCache $cache,
    ) {
    }

    /**
     * Ocena gotowa dla szablonu albo `null`, gdy jej nie ma.
     *
     * Zwracana tablica to odpowiedź huba wzbogacona o `ring` — łuki policzone przez
     * ScoreRing, żeby szablon nie liczył geometrii.
     *
     * @param bool $force pominąć pamięć podręczną (po ręcznym odświeżeniu ekranu)
     *
     * @return array<string, mixed>|null
     */
    public function get(bool $force = false): ?array
    {
        if (!$this->state->get('connected', false)) {
            return null;
        }

        if (!$force) {
            $cached = $this->cache->get(self::CACHE_KEY);
            if (\is_array($cached)) {
                return empty($cached['available']) ? null : $cached;
            }
        }

        $result = $this->hub->score();
        if (!$result['ok']) {
            $this->cache->set(self::CACHE_KEY, ['available' => false], self::TTL_SILENCE);

            return null;
        }

        $data = $result['data'];

        // `available: false` przychodzi na progu Free (hub nie wydaje wtedy ŻADNEJ liczby
        // o stanie sklepu) i wtedy, gdy oceny jeszcze nie policzono. Ekran robi w obu
        // wypadkach to samo — chowa pierścień — ale odpowiedź buforujemy na pełną godzinę,
        // bo to jest odpowiedź huba, a nie jego cisza.
        if (empty($data['available'])) {
            $this->cache->set(self::CACHE_KEY, ['available' => false], self::TTL);

            return null;
        }

        $data['ring'] = ScoreRing::segments(\is_array($data['areas'] ?? null) ? $data['areas'] : []);
        $this->cache->set(self::CACHE_KEY, $data, self::TTL);

        return $data;
    }

    /** Po rozłączeniu i po wymianie klucza stara ocena nie ma prawa zostać na ekranie. */
    public function forget(): void
    {
        $this->cache->delete(self::CACHE_KEY);
    }
}
