<?php

namespace App\HoyoverseBundle\MessageHandler\Batch;

use App\HoyoverseBundle\Message\Batch\HsrCatalogUpdateMessage;
use App\HoyoverseBundle\Service\Catalog\HsrCatalogUpdater;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * An aborted build throws: the transport retries it, then the previous catalog stays in use.
 */
#[AsMessageHandler]
class HsrCatalogUpdateHandler
{
    public function __construct(
        private readonly HsrCatalogUpdater $updater,
    ) {
    }

    public function __invoke(HsrCatalogUpdateMessage $message): void
    {
        $this->updater->update();
    }
}
