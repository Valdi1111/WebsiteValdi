<?php

namespace App\HoyoverseBundle;

use App\HoyoverseBundle\Message\Batch\CodeRedeemBatchMessage;
use App\HoyoverseBundle\Message\Batch\DailiesReminderBatchMessage;
use App\HoyoverseBundle\Message\Batch\EndgamesReminderBatchMessage;
use App\HoyoverseBundle\Message\Batch\ExpeditionsCheckBatchMessage;
use App\HoyoverseBundle\Message\Batch\HilichurlCheckBatchMessage;
use App\HoyoverseBundle\Message\Batch\HoyolabCheckInBatchMessage;
use App\HoyoverseBundle\Message\Batch\HoyolabMissedCheckInBatchMessage;
use App\HoyoverseBundle\Message\Batch\MimoCheckBatchMessage;
use App\HoyoverseBundle\Message\Batch\RealmCurrencyCheckBatchMessage;
use App\HoyoverseBundle\Message\Batch\ShopStatusCheckBatchMessage;
use App\HoyoverseBundle\Message\Batch\StaminaCheckBatchMessage;
use App\HoyoverseBundle\Message\Batch\SyncDiaryBatchMessage;
use App\HoyoverseBundle\Message\Batch\WeekliesReminderBatchMessage;
use App\HoyoverseBundle\Message\RegionalTaskMessageInterface;
use App\HoyoverseBundle\Message\TaskMessageInterface;
use App\HoyoverseBundle\Message\UpdateCookieMessage;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\NodeBuilder;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

class HoyoverseBundle extends AbstractBundle
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
        $configurator->parameters()->set('hoyoverse.domain_name', $config['domain_name']);

        $configurator->parameters()->set('hoyoverse.timezones', $config['timezones']);
        $configurator->parameters()->set('hoyoverse.timezone.SEA', $config['timezones']['SEA']);
        $configurator->parameters()->set('hoyoverse.timezone.EU', $config['timezones']['EU']);
        $configurator->parameters()->set('hoyoverse.timezone.NA', $config['timezones']['NA']);

        $hoyoverseTasks = $config['tasks'] ?? [];

        // Store root cron map configuration
        $configurator->parameters()->set('hoyoverse.tasks', $hoyoverseTasks);

        // Store flattened parameters for granular access
        foreach ($hoyoverseTasks as $taskName => $taskOptions) {
            $class = $taskOptions['message_class'];
            $isRegional = (bool) $taskOptions['regional'];
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
                    sprintf('hoyoverse.task.%s.%s', $taskName, $optionKey),
                    $optionValue
                );
            }
        }
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        /**
         * @param NodeBuilder<ArrayNodeDefinition> $tasksNode
         * @param string $nodeName
         * @param string $defaultClass
         * @param bool $isRegional
         * @param int|null $defaultJitter
         * @return mixed
         */
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
                        ->stringNode('SEA')->cannotBeEmpty()->defaultValue('Asia/Shanghai')->end()
                        ->stringNode('EU')->cannotBeEmpty()->defaultValue('Europe/Paris')->end()
                        ->stringNode('NA')->cannotBeEmpty()->defaultValue('America/New_York')->end()
                    ->end()
                ->end()
                ->arrayNode('tasks')
                    ->addDefaultsIfNotSet()
                    ->children();

        // 1. Global tasks (regional = false)
        $addCronNode($tasksNode, 'hoyolab_check_in', HoyolabCheckInBatchMessage::class);
        $addCronNode($tasksNode, 'hoyolab_missed_check_in', HoyolabMissedCheckInBatchMessage::class);
        $addCronNode($tasksNode, 'code_redeem', CodeRedeemBatchMessage::class);
        $addCronNode($tasksNode, 'stamina', StaminaCheckBatchMessage::class);
        $addCronNode($tasksNode, 'expedition', ExpeditionsCheckBatchMessage::class);
        $addCronNode($tasksNode, 'realm_currency', RealmCurrencyCheckBatchMessage::class);
        $addCronNode($tasksNode, 'shop_status', ShopStatusCheckBatchMessage::class);
        $addCronNode($tasksNode, 'mimo', MimoCheckBatchMessage::class, defaultJitter: 3);
        $addCronNode($tasksNode, 'hilichurl', HilichurlCheckBatchMessage::class, defaultJitter: 3);
        $addCronNode($tasksNode, 'sync_diary', SyncDiaryBatchMessage::class);
        $addCronNode($tasksNode, 'update_cookie', UpdateCookieMessage::class);

        // 2. Regional tasks (regional = true)
        $addCronNode($tasksNode, 'dailies_reminder', DailiesReminderBatchMessage::class, isRegional: true);
        $addCronNode($tasksNode, 'weeklies_reminder', WeekliesReminderBatchMessage::class, isRegional: true);
        $addCronNode($tasksNode, 'endgames_reminder', EndgamesReminderBatchMessage::class, isRegional: true);

        $tasksNode->end()->end()->end();
    }

}