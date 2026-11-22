<?php

namespace App\GamesBundle\Model\Pull\Manual;

/**
 * Shared constants of manual and filler pull rows.
 */
final class ManualPull
{
    /** Prefix of the provider string id of a manual S row (followed by a UUID) */
    public const string MANUAL_ID_PREFIX = 'manual_';

    /** Prefix of the provider string id of a filler row (followed by a UUID) */
    public const string FILLER_ID_PREFIX = 'filler_';

    /**
     * Prefix of the provider string id of an official S row (followed by a UUID). Official rows always carry a pull
     * number, which orders them inside their second: the prefix never decides their position.
     */
    public const string OFFICIAL_ID_PREFIX = 'official_';

    public const string FILLER_NAME = 'Unknown';

    public const int FILLER_RARITY = 0;

    /** Maximum distance between a manual S and the recorded S that replaces it */
    public const int MERGE_WINDOW_SECONDS = 86400;

    /** Wall-clock format of pull dates in requests and responses (no offset) */
    public const string DATE_FORMAT = 'Y-m-d H:i:s';
}
