<?php

namespace App\GryphlineBundle\MessageHandler\Batch;

use App\GryphlineBundle\Message\Batch\AkeCatalogUpdateMessage;
use App\GryphlineBundle\Service\Catalog\AkeCatalogUpdater;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * An aborted build throws: the transport retries it, then the previous catalog stays in use.
 */
#[AsMessageHandler]
class AkeCatalogUpdateHandler
{
    public function __construct(
        private readonly AkeCatalogUpdater $updater,
    ) {
    }

    public function __invoke(AkeCatalogUpdateMessage $message): void
    {
        $this->updater->update();
    }
}
