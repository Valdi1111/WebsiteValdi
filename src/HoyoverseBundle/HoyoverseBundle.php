<?php

namespace App\HoyoverseBundle;

use App\GamesBundle\DependencyInjection\GameTasksConfiguration;
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
use App\HoyoverseBundle\Message\Batch\SyncPullsBatchMessage;
use App\HoyoverseBundle\Message\Batch\WeekliesReminderBatchMessage;
use App\HoyoverseBundle\Message\UpdateCookieMessage;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
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

        $configurator->parameters()->set('hoyoverse.timezones', $config['timezones']);
        $configurator->parameters()->set('hoyoverse.timezone.SEA', $config['timezones']['SEA']);
        $configurator->parameters()->set('hoyoverse.timezone.EU', $config['timezones']['EU']);
        $configurator->parameters()->set('hoyoverse.timezone.NA', $config['timezones']['NA']);

        GameTasksConfiguration::registerTaskParameters($configurator, 'hoyoverse', $config['tasks'] ?? []);
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $tasksNode = $definition->rootNode()
            ->children()
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
        GameTasksConfiguration::addTaskNode($tasksNode, 'hoyolab_check_in', HoyolabCheckInBatchMessage::class);
        GameTasksConfiguration::addTaskNode($tasksNode, 'hoyolab_missed_check_in', HoyolabMissedCheckInBatchMessage::class);
        GameTasksConfiguration::addTaskNode($tasksNode, 'code_redeem', CodeRedeemBatchMessage::class);
        GameTasksConfiguration::addTaskNode($tasksNode, 'stamina', StaminaCheckBatchMessage::class);
        GameTasksConfiguration::addTaskNode($tasksNode, 'expedition', ExpeditionsCheckBatchMessage::class);
        GameTasksConfiguration::addTaskNode($tasksNode, 'realm_currency', RealmCurrencyCheckBatchMessage::class);
        GameTasksConfiguration::addTaskNode($tasksNode, 'shop_status', ShopStatusCheckBatchMessage::class);
        GameTasksConfiguration::addTaskNode($tasksNode, 'mimo', MimoCheckBatchMessage::class, defaultJitter: 3);
        GameTasksConfiguration::addTaskNode($tasksNode, 'hilichurl', HilichurlCheckBatchMessage::class, defaultJitter: 3);
        GameTasksConfiguration::addTaskNode($tasksNode, 'sync_diary', SyncDiaryBatchMessage::class);
        GameTasksConfiguration::addTaskNode($tasksNode, 'sync_pulls', SyncPullsBatchMessage::class);
        GameTasksConfiguration::addTaskNode($tasksNode, 'update_cookie', UpdateCookieMessage::class);

        // 2. Regional tasks (regional = true)
        GameTasksConfiguration::addTaskNode($tasksNode, 'dailies_reminder', DailiesReminderBatchMessage::class, regional: true);
        GameTasksConfiguration::addTaskNode($tasksNode, 'weeklies_reminder', WeekliesReminderBatchMessage::class, regional: true);
        GameTasksConfiguration::addTaskNode($tasksNode, 'endgames_reminder', EndgamesReminderBatchMessage::class, regional: true);

        $tasksNode->end()->end()->end();
    }

}
