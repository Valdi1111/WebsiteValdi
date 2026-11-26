<?php

namespace App\HoyoverseBundle\Message\Batch;

use App\GamesBundle\Message\TaskMessageInterface;
use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * Scheduled rebuild of the Honkai: Star Rail banner catalog ("hsr_catalog_update" task).
 */
#[AsMessage('hoyoverse')]
class HsrCatalogUpdateMessage implements TaskMessageInterface
{
}
