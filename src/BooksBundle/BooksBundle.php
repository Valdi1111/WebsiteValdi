<?php

namespace App\BooksBundle;

use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

class BooksBundle extends AbstractBundle
{

    public function getPath(): string
    {
        return dirname(__DIR__ . '/src');
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import('./config/services.yaml');
        $container->parameters()->set('books.domain_name', $config['domain_name']);
        $container->parameters()->set('books.base_folder', $config['base_folder']);
        $container->parameters()->set('books.covers_folder', $config['covers_folder']);
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
            ->scalarNode('domain_name')->defaultNull()->end()
            ->scalarNode('base_folder')->defaultNull()->end()
            ->scalarNode('covers_folder')->defaultNull()->end()
            ->end();
    }

}