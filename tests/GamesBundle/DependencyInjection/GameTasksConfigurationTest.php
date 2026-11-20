<?php

namespace App\Tests\GamesBundle\DependencyInjection;

use App\GamesBundle\DependencyInjection\GameTasksConfiguration;
use App\GryphlineBundle\Message\Batch\DailiesReminderBatchMessage;
use App\GryphlineBundle\Message\Batch\StaminaCheckBatchMessage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

class GameTasksConfigurationTest extends TestCase
{
    private function processTasks(array $tasksConfig): array
    {
        $treeBuilder = new TreeBuilder('provider');
        $tasksNode = $treeBuilder->getRootNode()
            ->children()
                ->arrayNode('tasks')
                    ->addDefaultsIfNotSet()
                    ->children();

        GameTasksConfiguration::addTaskNode($tasksNode, 'stamina', StaminaCheckBatchMessage::class);
        GameTasksConfiguration::addTaskNode($tasksNode, 'dailies_reminder', DailiesReminderBatchMessage::class, regional: true, defaultJitter: 3);

        $tasksNode->end()->end()->end();

        return (new Processor())->process($treeBuilder->buildTree(), [['tasks' => $tasksConfig]])['tasks'];
    }

    private function createConfigurator(ContainerBuilder $container): ContainerConfigurator
    {
        $instanceof = [];

        return new ContainerConfigurator($container, new PhpFileLoader($container, new FileLocator(__DIR__)), $instanceof, __DIR__, __FILE__);
    }

    public function testTaskNodesApplyDefaults(): void
    {
        $tasks = $this->processTasks(['stamina' => ['cron' => '*/30 * * * *']]);

        $this->assertSame([
            'cron' => '*/30 * * * *',
            'enabled' => true,
            'message_class' => StaminaCheckBatchMessage::class,
            'regional' => false,
            'jitter' => null,
        ], $tasks['stamina']);
        $this->assertTrue($tasks['dailies_reminder']['regional']);
        $this->assertSame(3, $tasks['dailies_reminder']['jitter']);
        $this->assertNull($tasks['dailies_reminder']['cron']);
    }

    public function testRegistersWholeAndFlattenedParameters(): void
    {
        $container = new ContainerBuilder();
        $tasks = $this->processTasks(['stamina' => ['cron' => '*/30 * * * *']]);

        GameTasksConfiguration::registerTaskParameters($this->createConfigurator($container), 'provider', $tasks);

        $this->assertSame($tasks, $container->getParameter('provider.tasks'));
        $this->assertSame('*/30 * * * *', $container->getParameter('provider.task.stamina.cron'));
        $this->assertSame(StaminaCheckBatchMessage::class, $container->getParameter('provider.task.stamina.message_class'));
        $this->assertTrue($container->getParameter('provider.task.dailies_reminder.regional'));
    }

    public function testRejectsUnknownMessageClass(): void
    {
        $tasks = $this->processTasks(['stamina' => ['message_class' => 'App\\Missing\\Message']]);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('does not exist');
        GameTasksConfiguration::registerTaskParameters($this->createConfigurator(new ContainerBuilder()), 'provider', $tasks);
    }

    public function testRejectsGlobalMessageForRegionalTask(): void
    {
        $tasks = $this->processTasks(['dailies_reminder' => ['message_class' => StaminaCheckBatchMessage::class]]);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Regional task "dailies_reminder"');
        GameTasksConfiguration::registerTaskParameters($this->createConfigurator(new ContainerBuilder()), 'provider', $tasks);
    }
}
