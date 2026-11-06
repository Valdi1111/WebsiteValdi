<?php

namespace App\HoyoverseBundle\Service;

use App\CoreBundle\Service\GameUtilsService;

class HoyolabUtilsService extends GameUtilsService
{
    public const string DS_SALT = '6s25p5ox5y14umn1p61aqyyvbvvl3lrt';

    /**
     * Generates the required DS (Dynamic Secret) string.
     */
    public function generateDS(): string
    {
        $time = (string) new \DateTime()->getTimestamp();
        $random = $this->randomString();
        $hash = $this->hash("salt=" . self::DS_SALT . "&t={$time}&r={$random}");

        return "{$time},{$random},{$hash}";
    }
}
