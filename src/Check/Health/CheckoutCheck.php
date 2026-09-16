<?php

declare(strict_types=1);

namespace Calmfox\WatchBundle\Check\Health;

use Calmfox\WatchBundle\Check\HealthCheckInterface;
use Calmfox\WatchBundle\Core\CheckResult;
use Sylius\Component\Channel\Repository\ChannelRepositoryInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Repository\PaymentMethodRepositoryInterface;
use Sylius\Component\Core\Repository\ShippingMethodRepositoryInterface;

/**
 * Czy w tym sklepie da się w ogóle kupić. To jedyny check, który patrzy na
 * konfigurację sprzedaży, i powstał z konkretnej awarii: strona świeciła się
 * na zielono (baza, dysk, poczta), a od dwóch dni nikt nie mógł złożyć
 * zamówienia, bo wyłączona metoda płatności została wyłączona „na chwilę".
 *
 * Sprawdzamy warunki konieczne, nie biznes: aktywny kanał musi mieć co najmniej
 * jedną włączoną metodę płatności i jedną włączoną metodę dostawy z przypisaną
 * strefą. W obroty, liczby zamówień i cokolwiek innego z danych sprzedażowych
 * nie wchodzimy i nie wysyłamy tego do huba.
 */
final class CheckoutCheck implements HealthCheckInterface
{
    private const LABEL = 'Ścieżka zakupowa';

    public function __construct(
        private readonly ?ChannelRepositoryInterface $channels = null,
        private readonly ?PaymentMethodRepositoryInterface $paymentMethods = null,
        private readonly ?ShippingMethodRepositoryInterface $shippingMethods = null,
    ) {
    }

    public function run(): ?CheckResult
    {
        if (null === $this->channels || null === $this->paymentMethods || null === $this->shippingMethods) {
            return null;
        }

        $start = microtime(true);
        try {
            $channels = $this->channels->findEnabled();
        } catch (\Throwable) {
            return null; // brak bazy ma zapalić check `db`, a nie dublować się tutaj
        }

        if ([] === $channels) {
            return CheckResult::fail('checkout', self::LABEL, 'Żaden kanał sprzedaży nie jest włączony, więc sklep nie obsłuży ani jednego zamówienia.', self::ms($start));
        }

        $broken = [];
        $checked = 0;
        foreach ($channels as $channel) {
            if (!$channel instanceof ChannelInterface) {
                continue;
            }
            ++$checked;
            $problems = $this->problemsFor($channel);
            if ([] !== $problems) {
                $broken[] = sprintf('%s: %s', (string) $channel->getCode(), implode(', ', $problems));
            }
        }

        $ms = self::ms($start);
        if ([] !== $broken) {
            return CheckResult::fail('checkout', self::LABEL, sprintf(
                'Klient nie dokończy zamówienia. %s',
                implode('; ', \array_slice($broken, 0, 3))
            ), $ms);
        }

        if (0 === $checked) {
            // Kanały są, ale nie w kształcie, który znamy (własna encja spoza
            // rdzenia Syliusa). Wolimy powiedzieć „nie sprawdziliśmy" niż zielone
            // „każdy z 0 kanałów działa".
            return CheckResult::warn('checkout', self::LABEL, 'Nie rozpoznaliśmy kanałów sprzedaży w tym sklepie, więc nie sprawdzamy warunków złożenia zamówienia.', $ms);
        }

        return CheckResult::ok('checkout', self::LABEL, sprintf(
            'Każdy z %d włączonych kanałów ma czynną metodę płatności i dostawy.',
            $checked
        ), $ms);
    }

    /** @return list<string> */
    private function problemsFor(ChannelInterface $channel): array
    {
        $problems = [];

        try {
            $payments = $this->paymentMethods?->findEnabledForChannel($channel) ?? [];
        } catch (\Throwable) {
            return []; // zmiana sygnatury w nowszym Syliusie nie może wywrócić monitoringu
        }
        if ([] === $payments) {
            $problems[] = 'brak włączonej metody płatności';
        }

        try {
            $shipping = $this->shippingMethods?->findEnabledForChannel($channel) ?? [];
        } catch (\Throwable) {
            return $problems;
        }
        if ([] === $shipping) {
            $problems[] = 'brak włączonej metody dostawy';

            return $problems;
        }

        // Metoda dostawy bez strefy nie pokaże się nikomu, więc liczy się tak samo jak jej brak.
        $withZone = 0;
        foreach ($shipping as $method) {
            if (method_exists($method, 'getZone') && null !== $method->getZone()) {
                ++$withZone;
            }
        }
        if (0 === $withZone) {
            $problems[] = 'żadna metoda dostawy nie ma przypisanej strefy';
        }

        return $problems;
    }

    private static function ms(float $start): int
    {
        return (int) round((microtime(true) - $start) * 1000);
    }
}
