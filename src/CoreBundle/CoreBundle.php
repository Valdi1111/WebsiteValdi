<?php

namespace App\CoreBundle;

use App\CoreBundle\Attribute\AsChatAction;
use App\CoreBundle\Attribute\AsChatCommand;
use App\CoreBundle\Doctrine\EncryptedTextType;
use App\CoreBundle\Model\Chat\ChatReplySenderInterface;
use App\CoreBundle\Service\Encryption\DatabaseCipher;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

class CoreBundle extends AbstractBundle
{
    /** @var (\Closure(): DatabaseCipher)|null Resolver set by this instance, removed on shutdown */
    private ?\Closure $cipherResolver = null;

    public function getPath(): string
    {
        return dirname(__DIR__ . '/src');
    }

    public function boot(): void
    {
        // DBAL types are built by Doctrine, outside the container: the encrypted column type gets the cipher lazily,
        // on the first value that needs it, so a malformed key only breaks what uses encrypted columns.
        $container = $this->container;
        $this->cipherResolver = static fn (): DatabaseCipher => $container->get(DatabaseCipher::class);
        EncryptedTextType::setCipherResolver($this->cipherResolver);
    }

    public function shutdown(): void
    {
        // Leaves in place the resolver of a kernel booted after this one
        if ($this->cipherResolver !== null) {
            EncryptedTextType::clearCipherResolver($this->cipherResolver);
            $this->cipherResolver = null;
        }
    }

    public function prependExtension(ContainerConfigurator $configurator, ContainerBuilder $container): void
    {
        $configurator->import('./config/packages/');
    }

    public function loadExtension(array $config, ContainerConfigurator $configurator, ContainerBuilder $container): void
    {
        $configurator->import('./config/services.yaml');

        // Chat commands and buttons, found by their attribute; the registries read the rest of the attribute from the class
        $container->registerAttributeForAutoconfiguration(AsChatCommand::class, static function (ChildDefinition $definition, AsChatCommand $attribute): void {
            $definition->addTag(AsChatCommand::TAG, ['name' => $attribute->name]);
        });
        $container->registerAttributeForAutoconfiguration(AsChatAction::class, static function (ChildDefinition $definition, AsChatAction $attribute): void {
            $definition->addTag(AsChatAction::TAG, ['name' => $attribute->name]);
        });
        // Here and not with #[AutoconfigureTag] on the interface: Model/ is not scanned for services, so an attribute
        // there is never read
        $container->registerForAutoconfiguration(ChatReplySenderInterface::class)->addTag(ChatReplySenderInterface::TAG);

        $configurator->parameters()->set('core.database_encryption_key', $config['database_encryption_key']);
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('database_encryption_key')
                    ->info('Base64 of 32 random bytes encrypting the encrypted_text columns; empty or null stores them in plain text.')
                    ->defaultNull()
                ->end()
            ->end();
    }

}