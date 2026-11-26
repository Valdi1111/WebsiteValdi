<?php

namespace App\HoyoverseBundle\Message\Batch;

use App\GamesBundle\Message\TaskMessageInterface;
use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * Scheduled rebuild of the Genshin Impact banner catalog ("gi_catalog_update" task).
 */
#[AsMessage('hoyoverse')]
class GiCatalogUpdateMessage implements TaskMessageInterface
{
}
