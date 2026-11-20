<?php

namespace App\GryphlineBundle;

use App\GamesBundle\DependencyInjection\GameTasksConfiguration;
use App\GryphlineBundle\Message\Batch\DailiesReminderBatchMessage;
use App\GryphlineBundle\Message\Batch\SkportCheckInBatchMessage;
use App\GryphlineBundle\Message\Batch\SkportMissedCheckInBatchMessage;
use App\GryphlineBundle\Message\Batch\StaminaCheckBatchMessage;
use App\GryphlineBundle\Message\Batch\SyncPullsBatchMessage;
use App\GryphlineBundle\Message\Batch\WeekliesReminderBatchMessage;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

class GryphlineBundle extends AbstractBundle
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
        // Load services
        $configurator->import('./config/services.yaml');

        // Load parameters
        $configurator->parameters()->set('gryphline.timezones', $config['timezones'] ?? []);
        $configurator->parameters()->set('gryphline.timezone.US_EU', $config['timezones']['US_EU']);
        $configurator->parameters()->set('gryphline.timezone.SEA', $config['timezones']['SEA']);

        GameTasksConfiguration::registerTaskParameters($configurator, 'gryphline', $config['tasks'] ?? []);
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $tasksNode = $definition->rootNode()
            ->children()
                ->arrayNode('timezones')
                    ->normalizeKeys(false)
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->stringNode('US_EU')->cannotBeEmpty()->defaultValue('Europe/Paris')->end()
                        ->stringNode('SEA')->cannotBeEmpty()->defaultValue('Asia/Shanghai')->end()
                    ->end()
                ->end()
                ->arrayNode('tasks')
                    ->addDefaultsIfNotSet()
                    ->children();

        // 1. Global tasks
        GameTasksConfiguration::addTaskNode($tasksNode, 'skport_check_in', SkportCheckInBatchMessage::class);
        GameTasksConfiguration::addTaskNode($tasksNode, 'skport_missed_check_in', SkportMissedCheckInBatchMessage::class);
        GameTasksConfiguration::addTaskNode($tasksNode, 'stamina', StaminaCheckBatchMessage::class);
        GameTasksConfiguration::addTaskNode($tasksNode, 'sync_pulls', SyncPullsBatchMessage::class);

        // 2. Regional tasks (regional = true)
        GameTasksConfiguration::addTaskNode($tasksNode, 'dailies_reminder', DailiesReminderBatchMessage::class, regional: true);
        GameTasksConfiguration::addTaskNode($tasksNode, 'weeklies_reminder', WeekliesReminderBatchMessage::class, regional: true);

        $tasksNode->end()->end();
    }
}
