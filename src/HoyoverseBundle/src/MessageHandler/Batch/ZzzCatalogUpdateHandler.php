<?php

namespace App\HoyoverseBundle\MessageHandler\Batch;

use App\HoyoverseBundle\Message\Batch\ZzzCatalogUpdateMessage;
use App\HoyoverseBundle\Service\Catalog\ZzzCatalogUpdater;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * An aborted build throws: the transport retries it, then the previous catalog stays in use.
 */
#[AsMessageHandler]
class ZzzCatalogUpdateHandler
{
    public function __construct(
        private readonly ZzzCatalogUpdater $updater,
    ) {
    }

    public function __invoke(ZzzCatalogUpdateMessage $message): void
    {
        $this->updater->update();
    }
}
