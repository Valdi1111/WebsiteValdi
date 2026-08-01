<?php

namespace App\AnimeBundle;

use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

class AnimeBundle extends AbstractBundle
{

    public function getPath(): string
    {
        return dirname(__DIR__ . '/src');
    }

    public function prependExtension(ContainerConfigurator $configurator, ContainerBuilder $container): void
    {
        $configurator->import('./config/packages/');
    }

    public function loadExtension(array $config, ContainerConfigurator $configurator, ContainerBuilder $container): void
    {
        $configurator->import('./config/services.yaml');
        $configurator->parameters()->set('anime.domain_name', $config['domain_name']);
        $configurator->parameters()->set('anime.base_folder', $config['base_folder']);
        $configurator->parameters()->set('anime.temp_folder', $config['temp_folder']);
        $configurator->parameters()->set('anime.myanimelist.url', $config['myanimelist']['url']);
        $configurator->parameters()->set('anime.myanimelist.api_url', $config['myanimelist']['api_url']);
        $configurator->parameters()->set('anime.myanimelist.client_id', $config['myanimelist']['client_id']);
        $configurator->parameters()->set('anime.myanimelist.client_secret', $config['myanimelist']['client_secret']);
        $configurator->parameters()->set('anime.anilist.url', $config['anilist']['url']);
        $configurator->parameters()->set('anime.anilist.api_url', $config['anilist']['api_url']);
        $configurator->parameters()->set('anime.anilist.client_id', $config['anilist']['client_id']);
        $configurator->parameters()->set('anime.anilist.client_secret', $config['anilist']['client_secret']);
        $configurator->parameters()->set('anime.animeworld.url_regex', $config['animeworld']['url_regex']);
        $configurator->parameters()->set('anime.animeworld.url', $config['animeworld']['url']);
        $configurator->parameters()->set('anime.animeworld.api_url', $config['animeworld']['api_url']);
        $configurator->parameters()->set('anime.animeworld.client_id', $config['animeworld']['client_id']);
        $configurator->parameters()->set('anime.animeworld.api_key', $config['animeworld']['api_key']);
        $configurator->parameters()->set('anime.animeunity.url_regex', $config['animeunity']['url_regex']);
        $configurator->parameters()->set('anime.animeunity.url', $config['animeunity']['url']);
        $configurator->parameters()->set('anime.youtube_dl.bin_path', $config['youtube_dl']['bin_path']);
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
            ->scalarNode('domain_name')->defaultNull()->end()
            ->scalarNode('base_folder')->defaultNull()->end()
            ->scalarNode('temp_folder')->defaultNull()->end()
            ->arrayNode('myanimelist')
                ->children()
                    ->scalarNode('url')->defaultNull()->end()
                    ->scalarNode('api_url')->defaultNull()->end()
                    ->scalarNode('client_id')->defaultNull()->end()
                    ->scalarNode('client_secret')->defaultNull()->end()
                ->end()
            ->end()
            ->arrayNode('anilist')
                ->children()
                    ->scalarNode('url')->defaultNull()->end()
                    ->scalarNode('api_url')->defaultNull()->end()
                    ->scalarNode('client_id')->defaultNull()->end()
                    ->scalarNode('client_secret')->defaultNull()->end()
                ->end()
            ->end()
            ->arrayNode('animeworld')
                ->children()
                    ->scalarNode('url_regex')->defaultNull()->end()
                    ->scalarNode('url')->defaultNull()->end()
                    ->scalarNode('api_url')->defaultNull()->end()
                    ->scalarNode('client_id')->defaultNull()->end()
                    ->scalarNode('api_key')->defaultNull()->end()
                ->end()
            ->end()
            ->arrayNode('animeunity')
                ->children()
                    ->scalarNode('url_regex')->defaultNull()->end()
                    ->scalarNode('url')->defaultNull()->end()
                ->end()
            ->end()
            ->arrayNode('youtube_dl')
                ->children()
                    ->scalarNode('bin_path')->defaultNull()->end()
                ->end()
            ->end();
    }

}