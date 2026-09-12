<?php

namespace App\HoyoverseBundle;

use App\HoyoverseBundle\Message\CodesRedeemMessage;
use App\HoyoverseBundle\Message\HoyolabCheckInMessage;
use App\HoyoverseBundle\Message\DailiesReminderMessage;
use App\HoyoverseBundle\Message\ExpeditionCheckMessage;
use App\HoyoverseBundle\Message\HilichurlTaskMessage;
use App\HoyoverseBundle\Message\HowlScratchCardMessage;
use App\HoyoverseBundle\Message\MimoTaskMessage;
use App\HoyoverseBundle\Message\HoyolabMissedCheckInMessage;
use App\HoyoverseBundle\Message\RealmCurrencyMessage;
use App\HoyoverseBundle\Message\RegionalTaskMessageInterface;
use App\HoyoverseBundle\Message\ShopStatusMessage;
use App\HoyoverseBundle\Message\StaminaCheckMessage;
use App\HoyoverseBundle\Message\TaskMessageInterface;
use App\HoyoverseBundle\Message\UpdateCookieMessage;
use App\HoyoverseBundle\Message\WeekliesReminderMessage;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
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
        $addCronNode = function (
            ArrayNodeDefinition $node,
            string $defaultClass,
            string $defaultCron,
            bool $isRegional = false,
            ?int $defaultJitter = null
        ) {
            $child = $node
                ->addDefaultsIfNotSet()
                ->children()
                ->booleanNode('enabled')->defaultTrue()->end()
                ->scalarNode('message_class')->cannotBeEmpty()->defaultValue($defaultClass)->end()
                ->scalarNode('cron')->cannotBeEmpty()->defaultValue($defaultCron)->end()
                ->booleanNode('regional')->defaultValue($isRegional)->end();

            $jitterNode = $child->integerNode('jitter')->min(0);
            if ($defaultJitter !== null) {
                $jitterNode->defaultValue($defaultJitter);
            } else {
                $jitterNode->defaultNull();
            }
            $jitterNode->end();

            return $child->end();
        };

        $rootNode = $definition->rootNode();
        $hoyoverseNode = $rootNode
            ->children()
            ->arrayNode('tasks')
            ->addDefaultsIfNotSet()
            ->children();

        // 1. Global tasks (regional = false)
        $addCronNode($hoyoverseNode->arrayNode('hoyolab_check_in'), HoyolabCheckInMessage::class, '0 20 * * *');
        $addCronNode($hoyoverseNode->arrayNode('hoyolab_missed_check_in'), HoyolabMissedCheckInMessage::class, '0 14 * * *');
        $addCronNode($hoyoverseNode->arrayNode('code_redeem'), CodesRedeemMessage::class, '*/15 * * * *');
        $addCronNode($hoyoverseNode->arrayNode('stamina'), StaminaCheckMessage::class, '*/30 * * * *');
        $addCronNode($hoyoverseNode->arrayNode('expedition'), ExpeditionCheckMessage::class, '*/30 * * * *');
        $addCronNode($hoyoverseNode->arrayNode('realm_currency'), RealmCurrencyMessage::class, '0 * * * *');
        $addCronNode($hoyoverseNode->arrayNode('shop_status'), ShopStatusMessage::class, '0 * * * *');
        $addCronNode($hoyoverseNode->arrayNode('mimo'), MimoTaskMessage::class, '0 */6 * * *', defaultJitter: 3);
        $addCronNode($hoyoverseNode->arrayNode('hilichurl'), HilichurlTaskMessage::class, '0 11 * * *', defaultJitter: 3);
        $addCronNode($hoyoverseNode->arrayNode('update_cookie'), UpdateCookieMessage::class, '0 3 * * *');

        // 2. Regional tasks (regional = true)
        $addCronNode($hoyoverseNode->arrayNode('howl_scratch_card'), HowlScratchCardMessage::class, '0 21 * * *', isRegional: true);
        $addCronNode($hoyoverseNode->arrayNode('dailies_reminder'), DailiesReminderMessage::class, '0 21 * * *', isRegional: true);
        $addCronNode($hoyoverseNode->arrayNode('weeklies_reminder'), WeekliesReminderMessage::class, '0 21 * * 0', isRegional: true);

        $hoyoverseNode->end()->end()->end();
    }

}