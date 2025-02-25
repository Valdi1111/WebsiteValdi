<?php

namespace App\PasswordsBundle;

use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

class PasswordsBundle extends AbstractBundle
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
        $container->parameters()->set('passwords.domain_name', $config['domain_name']);
        $container->parameters()->set('passwords.encryption_key', $config['encryption_key']);
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('domain_name')->defaultNull()->end()
                ->scalarNode('encryption_key')->defaultNull()->end()
            ->end();
    }

}