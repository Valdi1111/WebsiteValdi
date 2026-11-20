<?php

namespace App\GryphlineBundle\Service\Pull;

use App\CoreBundle\Service\AbstractPullOperationTracker;
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
        #[Autowire(param: 'gryphline.domain_name')]
        ?string $gryphlineDomain = null,
        #[Target('gryphline')]
        ?LoggerInterface $logger = null,
    ) {
        parent::__construct($cachePool, $mercureHub, $gryphlineDomain, $logger);
    }

    protected function getFallbackDomain(): string
    {
        return 'gryphline.local';
    }
}
