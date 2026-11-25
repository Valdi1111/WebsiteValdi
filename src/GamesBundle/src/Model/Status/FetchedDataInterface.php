<?php

namespace App\GamesBundle\Model\Status;

/**
 * Section data read from a cache, which knows when the API answered: the collector keeps the oldest fetch time of a
 * profile (notes, endgames, check-in), so a view never shows cached data as newer than it is.
 */
interface FetchedDataInterface
{
    public function getFetchedAt(): ?\DateTimeImmutable;
}
