<?php

namespace App\HoyoverseBundle\Message\Batch;

use App\GamesBundle\Message\TaskMessageInterface;
use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * Scheduled rebuild of the Zenless Zone Zero banner catalog ("zzz_catalog_update" task).
 */
#[AsMessage('hoyoverse')]
class ZzzCatalogUpdateMessage implements TaskMessageInterface
{
}
