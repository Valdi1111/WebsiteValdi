<?php

namespace App\AnimeBundle\Exception;

use App\AnimeBundle\Model\TrackerIdentifier;
use Doctrine\Common\Collections\Collection;
use Exception;
use Throwable;

class CacheAnimeNotFoundException extends Exception
{
    /**
     * @param Collection<TrackerIdentifier> $identifiers Mapping of tracking services and ids checked
     */
    public function __construct(Collection $identifiers, int $code = 0, ?Throwable $previous = null)
    {
        $details = [];
        foreach ($identifiers as $identifier) {
            $details[] = sprintf('%s ID: %d', strtoupper($identifier->getTracker()), $identifier->getTrackerId());
        }

        $message = !empty($details)
            ? sprintf("This series was not found in your tracking list (%s).", implode(', ', $details))
            : "This series was not found in your tracking list.";

        parent::__construct($message, $code, $previous);
    }
}
