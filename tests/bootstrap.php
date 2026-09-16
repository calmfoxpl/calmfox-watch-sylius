<?php

/**
 * Autoloader PSR-4 bez Composera: testy rdzenia mają działać w każdym środowisku,
 * także tam, gdzie nie ma sieci na `composer install`. Ładujemy wyłącznie własne
 * przestrzenie nazw, więc próba użycia klasy Symfony w teście od razu się wywali
 * i pilnuje granicy „rdzeń niezależny od frameworka".
 */

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $roots = [
        'Calmfox\\WatchBundle\\Tests\\' => __DIR__.'/',
        'Calmfox\\WatchBundle\\' => __DIR__.'/../src/',
    ];

    foreach ($roots as $prefix => $dir) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }
        $path = $dir.str_replace('\\', '/', substr($class, \strlen($prefix))).'.php';
        if (is_file($path)) {
            require_once $path;
        }

        return;
    }
});
