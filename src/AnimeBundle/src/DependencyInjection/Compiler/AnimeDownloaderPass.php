<?php

namespace App\AnimeBundle\DependencyInjection\Compiler;

use App\AnimeBundle\Service\AnimeWorldService;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\RuntimeException;

class AnimeDownloaderPass implements CompilerPassInterface
{

    /**
     * @inheritDoc
     */
    public function process(ContainerBuilder $container): void
    {
        foreach ($container->findTaggedServiceIds('anime.downloader') as $id => $tags) {
            $definition = $container->getDefinition($id);
            $definition->clearTag('anime.downloader');
            $found = false;
            foreach ($tags as $attributes) {
                if (isset($attributes['config'])) {
                    $attributes['website'] = $container->getParameter("{$attributes['config']}.url");
                    $found = true;
                }
                $definition->addTag('anime.downloader', $attributes);
            }
            if(!$found) {
                throw new RuntimeException(sprintf('The service "%s" tagged "anime.downloader" must have a "config" attribute.', $id));
            }
        }
    }
}