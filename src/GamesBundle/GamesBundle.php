<?php

namespace App\GamesBundle;

use App\GamesBundle\Model\Game\PullItemIconResolverInterface;
use App\GamesBundle\Model\Profile\GameProfileProviderInterface;
use App\GamesBundle\Model\Status\ProfileSectionInterface;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Shared foundation for game provider bundles (HoyoverseBundle, GryphlineBundle, ...):
 * common game models and interfaces, pull tracking and icons, GeeTest/auth helpers,
 * and the unified games frontend served on a single host.
 */
class GamesBundle extends AbstractBundle
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

        $configurator->parameters()->set('games.domain_name', $config['domain_name']);
        // Provider key => role unlocking its tab, in tab order
        $configurator->parameters()->set('games.providers', array_map(
            static fn (array $provider): string => $provider['role'],
            $config['providers'],
        ));

        // The interface lives in Model/ (excluded from service discovery), so keep explicit autoconfiguration
        // alongside its #[AutoconfigureTag] attribute.
        $container->registerForAutoconfiguration(PullItemIconResolverInterface::class)
            ->addTag('games.pull_item_icon_resolver');
        $container->registerForAutoconfiguration(GameProfileProviderInterface::class)
            ->addTag(GameProfileProviderInterface::TAG);
        $container->registerForAutoconfiguration(ProfileSectionInterface::class)
            ->addTag(ProfileSectionInterface::TAG);
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('domain_name')->defaultNull()->end()
                ->arrayNode('providers')
                    ->info('Game providers shown as tabs, in order. The key is the provider route/API segment.')
                    ->useAttributeAsKey('key')
                    ->normalizeKeys(false)
                    ->arrayPrototype()
                        ->children()
                            ->stringNode('role')->isRequired()->cannotBeEmpty()->end()
                        ->end()
                    ->end()
                ->end()
            ->end();
    }
}
