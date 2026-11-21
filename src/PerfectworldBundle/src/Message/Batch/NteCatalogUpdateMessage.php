<?php

namespace App\PerfectworldBundle\Message\Batch;

use App\GamesBundle\Message\TaskMessageInterface;
use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * Scheduled rebuild of the NTE item catalog ("catalog_update" task).
 */
#[AsMessage('perfectworld')]
class NteCatalogUpdateMessage implements TaskMessageInterface
{
}
