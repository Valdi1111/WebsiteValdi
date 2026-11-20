<?php

namespace App\HoyoverseBundle\Service\Pull;

use App\CoreBundle\Service\AbstractPullOperationTracker;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Mercure\HubInterface;

class PullOperationTracker extends AbstractPullOperationTracker
{
    public function __construct(
        #[Target('hoyoverse.pull_import_status.cache')]
        CacheItemPoolInterface $cachePool,
        HubInterface $mercureHub,
        #[Autowire(param: 'hoyoverse.domain_name')]
        ?string $hoyoverseDomain = null,
        #[Target('hoyoverse')]
        ?LoggerInterface $logger = null,
    ) {
        parent::__construct($cachePool, $mercureHub, $hoyoverseDomain, $logger);
    }

    protected function getFallbackDomain(): string
    {
        return 'hoyoverse.local';
    }
}
