<?php

namespace App\GryphlineBundle\Message\Batch;

use App\CoreBundle\Message\FeatureFlagMessageInterface;
use App\CoreBundle\Message\TaskMessageInterface;

class StaminaCheckBatchMessage implements TaskMessageInterface, FeatureFlagMessageInterface
{
    public function getFeatureFlagField(): string
    {
        return 'staminaCheck';
    }
}
