<?php

namespace App\GryphlineBundle\Service\Pull;

use App\GamesBundle\Service\AbstractPullOperationTracker;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Mercure\HubInterface;

class PullOperationTracker extends AbstractPullOperationTracker
{
    public function __construct(
        #[Target('gryphline.pull_import_status.cache')]
        CacheItemPoolInterface $cachePool,
        HubInterface $mercureHub,
        #[Autowire(param: 'games.domain_name')]
        ?string $gamesDomain = null,
        #[Target('gryphline')]
        ?LoggerInterface $logger = null,
    ) {
        parent::__construct($cachePool, $mercureHub, $gamesDomain, $logger);
    }

    protected function getProviderKey(): string
    {
        return 'gryphline';
    }
}
