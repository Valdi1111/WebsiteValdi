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

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import('./config/services.yaml');
        $container->parameters()->set('anime.domain_name', $config['domain_name']);
        $container->parameters()->set('anime.base_folder', $config['base_folder']);
        $container->parameters()->set('anime.temp_folder', $config['temp_folder']);
        $container->parameters()->set('anime.download_extension', $config['download_extension']);
        $container->parameters()->set('anime.mal.url', $config['mal']['url']);
        $container->parameters()->set('anime.mal.client_id', $config['mal']['client_id']);
        $container->parameters()->set('anime.mal.client_secret', $config['mal']['client_secret']);
        $container->parameters()->set('anime.aw.url', $config['aw']['url']);
        $container->parameters()->set('anime.aw.api_url', $config['aw']['api_url']);
        $container->parameters()->set('anime.aw.client_id', $config['aw']['client_id']);
        $container->parameters()->set('anime.aw.api_key', $config['aw']['api_key']);
        $container->parameters()->set('anime.youtube_dl.path', $config['youtube_dl']['path']);
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
            ->scalarNode('domain_name')->defaultNull()->end()
            ->scalarNode('base_folder')->defaultNull()->end()
            ->scalarNode('temp_folder')->defaultNull()->end()
            ->scalarNode('download_extension')->defaultNull()->end()
            ->arrayNode('mal')
                ->children()
                    ->scalarNode('url')->defaultNull()->end()
                    ->scalarNode('client_id')->defaultNull()->end()
                    ->scalarNode('client_secret')->defaultNull()->end()
                ->end()
            ->end()
            ->arrayNode('aw')
                ->children()
                    ->scalarNode('url')->defaultNull()->end()
                    ->scalarNode('api_url')->defaultNull()->end()
                    ->scalarNode('client_id')->defaultNull()->end()
                    ->scalarNode('api_key')->defaultNull()->end()
                ->end()
            ->end()
            ->arrayNode('youtube_dl')
                ->children()
                    ->scalarNode('path')->defaultNull()->end()
                ->end()
            ->end();
    }

}