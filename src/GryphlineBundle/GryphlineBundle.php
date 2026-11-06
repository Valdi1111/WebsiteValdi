<?php

namespace App\GryphlineBundle;

use App\GryphlineBundle\Message\Batch\DailiesReminderBatchMessage;
use App\GryphlineBundle\Message\Batch\SkportCheckInBatchMessage;
use App\GryphlineBundle\Message\Batch\StaminaCheckBatchMessage;
use App\GryphlineBundle\Message\Batch\WeekliesReminderBatchMessage;
use App\CoreBundle\Message\RegionalTaskMessageInterface;
use App\CoreBundle\Message\TaskMessageInterface;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\NodeBuilder;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
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
        // Load configurations
        $configurator->import('./config/packages/');
    }

    public function loadExtension(array $config, ContainerConfigurator $configurator, ContainerBuilder $container): void
    {
        // Load services
        $configurator->import('./config/services.yaml');

        // Load parameters
        $configurator->parameters()->set('gryphline.domain_name', $config['domain_name'] ?? null);
        $configurator->parameters()->set('gryphline.timezones', $config['timezones'] ?? []);

        $gryphlineTasks = $config['tasks'] ?? [];
        $configurator->parameters()->set('gryphline.tasks', $gryphlineTasks);

        foreach ($gryphlineTasks as $taskName => $taskOptions) {
            $class = $taskOptions['message_class'];
            $isRegional = (bool) ($taskOptions['regional'] ?? false);

            if (!class_exists($class)) {
                throw new InvalidConfigurationException(sprintf(
                    'Configured message class "%s" for task "%s" does not exist.',
                    $class,
                    $taskName
                ));
            }

            if ($isRegional) {
                if (!is_subclass_of($class, RegionalTaskMessageInterface::class)) {
                    throw new InvalidConfigurationException(sprintf(
                        'Regional task "%s" must use a message class implementing "%s", but "%s" was given.',
                        $taskName,
                        RegionalTaskMessageInterface::class,
                        $class
                    ));
                }
            } else {
                if (!is_subclass_of($class, TaskMessageInterface::class)) {
                    throw new InvalidConfigurationException(sprintf(
                        'Global task "%s" must use a message class implementing "%s", but "%s" was given.',
                        $taskName,
                        TaskMessageInterface::class,
                        $class
                    ));
                }
            }

            foreach ($taskOptions as $optionKey => $optionValue) {
                $configurator->parameters()->set(
                    sprintf('gryphline.task.%s.%s', $taskName, $optionKey),
                    $optionValue
                );
            }
        }
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $addCronNode = function (
            NodeBuilder $tasksNode,
            string $nodeName,
            string $defaultClass,
            bool $isRegional = false,
            ?int $defaultJitter = null
        ) {
            return $tasksNode
                ->arrayNode($nodeName)
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultTrue()->end()
                        ->stringNode('message_class')->cannotBeEmpty()->defaultValue($defaultClass)->end()
                        ->stringNode('cron')->cannotBeEmpty()->defaultNull()->end()
                        ->booleanNode('regional')->defaultValue($isRegional)->end()
                        ->integerNode('jitter')->min(0)->defaultValue($defaultJitter)->end()
                    ->end()
                ->end();
        };

        $tasksNode = $definition->rootNode()
            ->children()
                ->scalarNode('domain_name')->defaultNull()->end()
                ->arrayNode('timezones')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->stringNode('EU')->cannotBeEmpty()->defaultValue('Europe/Paris')->end()
                        ->stringNode('CN')->cannotBeEmpty()->defaultValue('Asia/Shanghai')->end()
                    ->end()
                ->end()
                ->arrayNode('tasks')
                    ->addDefaultsIfNotSet()
                    ->children();

        // 1. Global tasks
        $addCronNode($tasksNode, 'skport_check_in', SkportCheckInBatchMessage::class);
        $addCronNode($tasksNode, 'stamina', StaminaCheckBatchMessage::class);

        // 2. Regional tasks
        $addCronNode($tasksNode, 'dailies_reminder', DailiesReminderBatchMessage::class, isRegional: true);
        $addCronNode($tasksNode, 'weeklies_reminder', WeekliesReminderBatchMessage::class, isRegional: true);

        $tasksNode->end()->end()->end();
    }
}
