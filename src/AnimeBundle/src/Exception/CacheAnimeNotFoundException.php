<?php

namespace App\AnimeBundle\Exception;

use Exception;
use Throwable;

class CacheAnimeNotFoundException extends Exception
{
    /**
     * @param array<string, int|string> $identifiers Mapping of tracking services and ids checked (e.g. ['myanimelist' => 1234, 'anilist' => 5678])
     */
    public function __construct(array $identifiers = [], int $code = 0, ?Throwable $previous = null)
    {
        $details = [];
        foreach ($identifiers as $service => $id) {
            $details[] = sprintf('%s ID: %s', strtoupper((string) $service), (string) $id);
        }

        $message = !empty($details)
            ? sprintf("This series was not found in your tracking list (%s).", implode(', ', $details))
            : "This series was not found in your tracking list.";

        parent::__construct($message, $code, $previous);
    }
}
