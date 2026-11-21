<?php

namespace App\PerfectworldBundle\MessageHandler\Batch;

use App\PerfectworldBundle\Message\Batch\NteCatalogUpdateMessage;
use App\PerfectworldBundle\Service\Catalog\NteCatalogUpdater;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * An aborted build throws: the transport retries it, then the previous catalog stays in use.
 */
#[AsMessageHandler]
class NteCatalogUpdateHandler
{
    public function __construct(
        private readonly NteCatalogUpdater $updater,
    ) {
    }

    public function __invoke(NteCatalogUpdateMessage $message): void
    {
        $this->updater->update();
    }
}
