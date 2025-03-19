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

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import('./config/packages/');
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import('./config/services.yaml');
        $container->parameters()->set('anime.domain_name', $config['domain_name']);
        $container->parameters()->set('anime.base_folder', $config['base_folder']);
        $container->parameters()->set('anime.temp_folder', $config['temp_folder']);
        $container->parameters()->set('anime.myanimelist.url', $config['myanimelist']['url']);
        $container->parameters()->set('anime.myanimelist.client_id', $config['myanimelist']['client_id']);
        $container->parameters()->set('anime.myanimelist.client_secret', $config['myanimelist']['client_secret']);
        $container->parameters()->set('anime.anilist.url', $config['anilist']['url']);
        $container->parameters()->set('anime.anilist.client_id', $config['anilist']['client_id']);
        $container->parameters()->set('anime.anilist.client_secret', $config['anilist']['client_secret']);
        $container->parameters()->set('anime.animeworld.url_regex', $config['animeworld']['url_regex']);
        $container->parameters()->set('anime.animeworld.url', $config['animeworld']['url']);
        $container->parameters()->set('anime.animeworld.api_url', $config['animeworld']['api_url']);
        $container->parameters()->set('anime.animeworld.client_id', $config['animeworld']['client_id']);
        $container->parameters()->set('anime.animeworld.api_key', $config['animeworld']['api_key']);
        $container->parameters()->set('anime.animeunity.url_regex', $config['animeunity']['url_regex']);
        $container->parameters()->set('anime.animeunity.url', $config['animeunity']['url']);
        $container->parameters()->set('anime.youtube_dl.bin_path', $config['youtube_dl']['bin_path']);
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
                    ->scalarNode('client_id')->defaultNull()->end()
                    ->scalarNode('client_secret')->defaultNull()->end()
                ->end()
            ->end()
            ->arrayNode('anilist')
                ->children()
                    ->scalarNode('url')->defaultNull()->end()
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