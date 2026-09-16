<?php

declare(strict_types=1);

namespace Calmfox\WatchBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('calmfox_watch');
        $root = $treeBuilder->getRootNode();

        $root
            ->children()
                ->scalarNode('api_url')
                    ->defaultNull()
                    ->info('Adres API Calmfox Watch. Domyślnie https://watch.calmfox.net, do nadpisania zmienną CALMFOX_WATCH_API_URL.')
                ->end()
                ->scalarNode('state_dir')
                    ->defaultNull()
                    ->info('Katalog stanu (sekret, znacznik parowania, historia). Domyślnie %kernel.project_dir%/var/calmfox-watch, do nadpisania zmienną CALMFOX_WATCH_STATE_DIR. Przy wdrożeniach z osobnym katalogiem na wydanie MUSI być współdzielony między wydaniami.')
                ->end()
                ->scalarNode('site_url')
                    ->defaultNull()
                    ->info('Adres sklepu (https://sklep.pl) używany do zbudowania adresu kontrolnego w poleceniach CLI, gdzie router nie zna hosta. W żądaniu HTTP nie jest potrzebny.')
                ->end()
                ->scalarNode('admin_path')
                    ->defaultValue('admin')
                    ->info('Prefiks panelu administracyjnego. Ustaw tak samo jak sylius_admin_path, jeżeli był zmieniany.')
                ->end()
                ->booleanNode('dashboard_widget')
                    ->defaultTrue()
                    ->info('Kafelek z kondycją sklepu na pulpicie panelu administracyjnego. Ustaw false, jeżeli pulpit ma zostać nietknięty.')
                ->end()
                ->scalarNode('admin_layout')
                    ->defaultValue('@SyliusAdmin/layout.html.twig')
                    ->info('Layout panelu administracyjnego. Nazwa różni się między Syliusem 1.x a 2.x, sprawdź w swojej wersji.')
                ->end()
                ->floatNode('disk_quota_gb')
                    ->defaultValue(0.0)
                    ->info('Limit dysku na koncie hostingowym w GB. Zero znaczy „nie znamy limitu" i tak też opisujemy check. Wartość podana na ekranie w panelu sklepu ma pierwszeństwo.')
                ->end()
                ->scalarNode('media_dir')
                    ->defaultNull()
                    ->info('Katalog mediów sklepu. Domyślnie %kernel.project_dir%/public/media.')
                ->end()
                ->scalarNode('mailer_dsn')
                    ->defaultNull()
                    ->info('Adres serwera poczty. Domyślnie czytany ze zmiennej MAILER_DSN.')
                ->end()
                ->scalarNode('elasticsearch_url')
                    ->defaultNull()
                    ->info('Adres klastra Elasticsearch. Puste = sklep go nie używa i check w ogóle nie powstaje.')
                ->end()
                ->scalarNode('messenger_table')
                    ->defaultValue('messenger_messages')
                    ->info('Tabela transportu doctrine Symfony Messenger.')
                ->end()
                ->arrayNode('failure_transports')
                    ->scalarPrototype()->end()
                    ->defaultValue(['failed'])
                    ->info('Nazwy kolejek na wiadomości nieudane (framework.messenger.failure_transport).')
                ->end()
                ->integerNode('heartbeat_interval')
                    ->defaultValue(600)
                    ->min(60)
                    ->info('Co ile sekund cron wywołuje calmfox:watch:heartbeat. Progi checku zadań cyklicznych liczą się z tej wartości.')
                ->end()
                ->scalarNode('platform_package')
                    ->defaultValue('sylius/sylius')
                    ->info('Pakiet, którego wersja jedzie jako wersja platformy i którego zmiany trafiają do historii jako „core".')
                ->end()
                ->scalarNode('composer_binary')
                    ->defaultValue('composer')
                    ->info('Polecenie Composera używane przez calmfox:watch:updates.')
                ->end()
            ->end();

        return $treeBuilder;
    }
}
