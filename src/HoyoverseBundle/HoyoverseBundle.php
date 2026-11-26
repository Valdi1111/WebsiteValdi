<?php

namespace App\HoyoverseBundle;

use App\GamesBundle\DependencyInjection\GameTasksConfiguration;
use App\GamesBundle\Model\Game\HasEndgamesInterface;
use App\HoyoverseBundle\Message\Batch\CodeRedeemBatchMessage;
use App\HoyoverseBundle\Message\Batch\DailiesReminderBatchMessage;
use App\HoyoverseBundle\Message\Batch\EndgamesReminderBatchMessage;
use App\HoyoverseBundle\Message\Batch\ExpeditionsCheckBatchMessage;
use App\HoyoverseBundle\Message\Batch\GiCatalogUpdateMessage;
use App\HoyoverseBundle\Message\Batch\HilichurlCheckBatchMessage;
use App\HoyoverseBundle\Message\Batch\HoyolabCheckInBatchMessage;
use App\HoyoverseBundle\Message\Batch\HoyolabMissedCheckInBatchMessage;
use App\HoyoverseBundle\Message\Batch\HsrCatalogUpdateMessage;
use App\HoyoverseBundle\Message\Batch\MimoCheckBatchMessage;
use App\HoyoverseBundle\Message\Batch\RealmCurrencyCheckBatchMessage;
use App\HoyoverseBundle\Message\Batch\ShopStatusCheckBatchMessage;
use App\HoyoverseBundle\Message\Batch\StaminaCheckBatchMessage;
use App\HoyoverseBundle\Message\Batch\SyncDiaryBatchMessage;
use App\HoyoverseBundle\Message\Batch\SyncPullsBatchMessage;
use App\HoyoverseBundle\Message\Batch\WeekliesReminderBatchMessage;
use App\HoyoverseBundle\Message\Batch\ZzzCatalogUpdateMessage;
use App\HoyoverseBundle\Message\UpdateCookieMessage;
use Symfony\Component\Config\Definition\Builder\NodeBuilder;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\Config\Resource\DirectoryResource;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

class HoyoverseBundle extends AbstractBundle
{
    /** Game services of the bundle, one class per game (App\HoyoverseBundle\Service\*Service) */
    private const string GAME_SERVICE_DIR = __DIR__ . '/src/Service';

    private const string GAME_SERVICE_NAMESPACE = 'App\\HoyoverseBundle\\Service\\';

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
        // Reminder window per game and mode: [game key => [mode key => ['days_before_end' => int]]]
        $configurator->parameters()->set('hoyoverse.endgames_reminder', $config['endgames_reminder']);
        // The configuration tree is built from the game classes: rebuild it when they change
        $container->addResource(new DirectoryResource(self::GAME_SERVICE_DIR, '/Service\.php$/'));
        foreach (self::getEndgameGameClasses() as $class) {
            foreach (new \ReflectionClass($class)->getTraits() as $trait) {
                $container->addResource(new FileResource((string) $trait->getFileName()));
            }
        }

        GameTasksConfiguration::registerTaskParameters($configurator, 'hoyoverse', $config['tasks'] ?? []);
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $rootNode = $definition->rootNode()->children();
        $rootNode
            ->arrayNode('timezones')
                ->addDefaultsIfNotSet()
                ->children()
                    ->stringNode('SEA')->cannotBeEmpty()->defaultValue('Asia/Shanghai')->end()
                    ->stringNode('EU')->cannotBeEmpty()->defaultValue('Europe/Paris')->end()
                    ->stringNode('NA')->cannotBeEmpty()->defaultValue('America/New_York')->end()
                ->end()
            ->end();

        self::addEndgamesReminderNode($rootNode);

        $tasksNode = $rootNode
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
        // Banner catalogs from irminsul.gg, one per game
        GameTasksConfiguration::addTaskNode($tasksNode, 'gi_catalog_update', GiCatalogUpdateMessage::class);
        GameTasksConfiguration::addTaskNode($tasksNode, 'hsr_catalog_update', HsrCatalogUpdateMessage::class);
        GameTasksConfiguration::addTaskNode($tasksNode, 'zzz_catalog_update', ZzzCatalogUpdateMessage::class);

        // 2. Regional tasks (regional = true)
        GameTasksConfiguration::addTaskNode($tasksNode, 'dailies_reminder', DailiesReminderBatchMessage::class, regional: true);
        GameTasksConfiguration::addTaskNode($tasksNode, 'weeklies_reminder', WeekliesReminderBatchMessage::class, regional: true);
        GameTasksConfiguration::addTaskNode($tasksNode, 'endgames_reminder', EndgamesReminderBatchMessage::class, regional: true);

        $tasksNode->end()->end()->end();
    }

    /**
     * Game services implementing HasEndgamesInterface, found in the game service directory (the container does not
     * exist yet while the configuration tree is built, so its tags cannot be used).
     *
     * @return array<string, class-string<HasEndgamesInterface>> endgames configuration key => class
     */
    public static function getEndgameGameClasses(): array
    {
        $classes = [];
        $files = glob(self::GAME_SERVICE_DIR . '/*Service.php') ?: [];
        sort($files);
        foreach ($files as $file) {
            $class = self::GAME_SERVICE_NAMESPACE . basename($file, '.php');
            if (!class_exists($class) || !is_subclass_of($class, HasEndgamesInterface::class) || new \ReflectionClass($class)->isAbstract()) {
                continue;
            }

            $key = $class::getEndgamesConfigKey();
            if (isset($classes[$key])) {
                throw new \LogicException(sprintf('Endgames configuration key "%s" is used by both %s and %s.', $key, $classes[$key], $class));
            }
            $classes[$key] = $class;
        }

        return $classes;
    }

    /**
     * hoyoverse.endgames_reminder.<game key>.<mode key>.days_before_end, one node per mode declared by the games: an
     * unknown game or mode (e.g. a typo) is a configuration error.
     */
    private static function addEndgamesReminderNode(NodeBuilder $rootNode): void
    {
        $gamesNode = $rootNode->arrayNode('endgames_reminder')
            ->info('Endgames Reminder window, per game and endgame mode.')
            ->addDefaultsIfNotSet()
            ->children();

        foreach (self::getEndgameGameClasses() as $gameKey => $class) {
            $modesNode = $gamesNode->arrayNode($gameKey)->addDefaultsIfNotSet()->children();
            foreach ($class::getEndgameDefinitions() as $definition) {
                $modesNode->arrayNode($definition->key)
                    ->info($definition->name)
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('days_before_end')
                            ->info('Daily reminder while the period ends within this many days (24 h each).')
                            ->min(1)
                            ->defaultValue($definition->defaultDaysBeforeEnd)
                        ->end()
                    ->end();
            }
        }
    }
}
