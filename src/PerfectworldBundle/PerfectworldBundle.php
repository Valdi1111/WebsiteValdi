<?php

namespace App\PerfectworldBundle;

use App\GamesBundle\DependencyInjection\GameTasksConfiguration;
use App\PerfectworldBundle\Message\Batch\NteCatalogUpdateMessage;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Perfect World games provider (currently Neverness to Everness) for the unified games frontend.
 */
class PerfectworldBundle extends AbstractBundle
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

        // No regional task: the schedule only needs the (empty) timezone map
        $configurator->parameters()->set('perfectworld.timezones', []);
        GameTasksConfiguration::registerTaskParameters($configurator, 'perfectworld', $config['tasks'] ?? []);
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $tasksNode = $definition->rootNode()
            ->children()
                ->arrayNode('tasks')
                    ->addDefaultsIfNotSet()
                    ->children();

        GameTasksConfiguration::addTaskNode($tasksNode, 'catalog_update', NteCatalogUpdateMessage::class);

        $tasksNode->end()->end();
    }
}
