<?php

namespace App\HoyoverseBundle\MessageHandler\Batch;

use App\HoyoverseBundle\Message\Batch\GiCatalogUpdateMessage;
use App\HoyoverseBundle\Service\Catalog\GiCatalogUpdater;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * An aborted build throws: the transport retries it, then the previous catalog stays in use.
 */
#[AsMessageHandler]
class GiCatalogUpdateHandler
{
    public function __construct(
        private readonly GiCatalogUpdater $updater,
    ) {
    }

    public function __invoke(GiCatalogUpdateMessage $message): void
    {
        $this->updater->update();
    }
}
