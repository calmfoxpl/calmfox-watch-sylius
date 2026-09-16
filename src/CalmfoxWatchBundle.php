<?php

declare(strict_types=1);

namespace Calmfox\WatchBundle;

use Calmfox\WatchBundle\Check\HealthCheckInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class CalmfoxWatchBundle extends Bundle
{
    /**
     * Jedyne źródło prawdy o wersji pakietu. Stąd czyta payload (`plugin`),
     * ekran w panelu sklepu i skrypt budujący paczkę, więc podbicie w jednym
     * miejscu wystarczy. Świadomie nie ma pola „version" w composer.json:
     * Composer wylicza je z tagu, a paczka do ręcznego wgrania nie ma tagu.
     */
    public const VERSION = '1.3.1';

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // Punkt rozszerzenia dla usług klienta (odpowiednik filtra
        // `calmfox_watch_health_checks` we wtyczce WordPressa): każda usługa
        // implementująca interfejs dopina się sama, o ile projekt ma włączone
        // autoconfigure. Przykład w README pakietu.
        $container->registerForAutoconfiguration(HealthCheckInterface::class)
            ->addTag('calmfox_watch.health_check');
    }
}
