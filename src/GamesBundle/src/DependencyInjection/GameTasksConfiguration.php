<?php

namespace App\GamesBundle\DependencyInjection;

use App\GamesBundle\Message\RegionalTaskMessageInterface;
use App\GamesBundle\Message\TaskMessageInterface;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\NodeBuilder;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/**
 * Shared definition of the scheduled tasks configuration of game provider bundles
 * (`<provider>.tasks.<task>: { enabled, message_class, cron, regional, jitter }`).
 */
final class GameTasksConfiguration
{
    /**
     * Adds a task node to the provider's `tasks` configuration.
     *
     * @param NodeBuilder<ArrayNodeDefinition> $tasksNode
     * @param class-string<TaskMessageInterface> $defaultMessageClass
     */
    public static function addTaskNode(
        NodeBuilder $tasksNode,
        string $name,
        string $defaultMessageClass,
        bool $regional = false,
        ?int $defaultJitter = null,
    ): void {
        $tasksNode
            ->arrayNode($name)
                ->addDefaultsIfNotSet()
                ->children()
                    ->booleanNode('enabled')->defaultTrue()->end()
                    ->stringNode('message_class')->cannotBeEmpty()->defaultValue($defaultMessageClass)->end()
                    ->stringNode('cron')->cannotBeEmpty()->defaultNull()->end()
                    ->booleanNode('regional')->defaultValue($regional)->end()
                    ->integerNode('jitter')->min(0)->defaultValue($defaultJitter)->end()
                ->end()
            ->end();
    }

    /**
     * Validates the processed tasks and exposes them as container parameters:
     * `<prefix>.tasks` (whole map) and `<prefix>.task.<task>.<option>` (flattened).
     *
     * @param array<string, array{enabled: bool, message_class: string, cron: ?string, regional: bool, jitter: ?int}> $tasks
     *
     * @throws InvalidConfigurationException when a message class is missing or does not match the task kind
     */
    public static function registerTaskParameters(ContainerConfigurator $configurator, string $prefix, array $tasks): void
    {
        $configurator->parameters()->set(sprintf('%s.tasks', $prefix), $tasks);

        foreach ($tasks as $taskName => $taskOptions) {
            $class = $taskOptions['message_class'];
            if (!class_exists($class)) {
                throw new InvalidConfigurationException(sprintf(
                    'Configured message class "%s" for task "%s" does not exist.',
                    $class,
                    $taskName
                ));
            }

            $isRegional = (bool) $taskOptions['regional'];
            $requiredInterface = $isRegional ? RegionalTaskMessageInterface::class : TaskMessageInterface::class;
            if (!is_subclass_of($class, $requiredInterface)) {
                throw new InvalidConfigurationException(sprintf(
                    '%s task "%s" must use a message class implementing "%s", but "%s" was given.',
                    $isRegional ? 'Regional' : 'Global',
                    $taskName,
                    $requiredInterface,
                    $class
                ));
            }

            foreach ($taskOptions as $optionKey => $optionValue) {
                $configurator->parameters()->set(
                    sprintf('%s.task.%s.%s', $prefix, $taskName, $optionKey),
                    $optionValue
                );
            }
        }
    }
}
