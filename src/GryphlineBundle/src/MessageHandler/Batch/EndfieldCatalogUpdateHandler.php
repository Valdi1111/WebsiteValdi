<?php

namespace App\GryphlineBundle\MessageHandler\Batch;

use App\GryphlineBundle\Message\Batch\EndfieldCatalogUpdateMessage;
use App\GryphlineBundle\Service\Catalog\EndfieldCatalogUpdater;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * An aborted build throws: the transport retries it, then the previous catalog stays in use.
 */
#[AsMessageHandler]
class EndfieldCatalogUpdateHandler
{
    public function __construct(
        private readonly EndfieldCatalogUpdater $updater,
    ) {
    }

    public function __invoke(EndfieldCatalogUpdateMessage $message): void
    {
        $this->updater->update();
    }
}
