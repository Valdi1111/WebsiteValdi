<?php

namespace App\GryphlineBundle\Message\Batch;

use App\GamesBundle\Message\TaskMessageInterface;
use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * Scheduled rebuild of the Endfield operator and weapon catalog from AkeData ("catalog_update" task).
 */
#[AsMessage('gryphline')]
class EndfieldCatalogUpdateMessage implements TaskMessageInterface
{
}
