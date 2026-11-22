<?php

namespace App\GamesBundle\Exception;

use Psr\Log\LogLevel;
use Symfony\Component\HttpKernel\Attribute\WithLogLevel;

/**
 * A manual pull operation breaks one of the shared rules (rendered as a 422 on /api/ routes).
 *
 * Logged as a notice: it is a user input error, not an application failure.
 */
#[WithLogLevel(LogLevel::NOTICE)]
class ManualPullViolationException extends \DomainException
{
    public const string INVALID_TARGET = 'invalid_target';
    public const string INVALID_ANCHOR = 'invalid_anchor';
    public const string PITY_REQUIRED = 'pity_required';
    public const string PITY_OUT_OF_RANGE = 'pity_out_of_range';
    public const string NO_ROOM = 'no_room';
    public const string DATE_OUT_OF_RANGE = 'date_out_of_range';
    public const string DATE_IN_FUTURE = 'date_in_future';
    public const string POSSIBLE_DUPLICATE = 'possible_duplicate';
    /** The item is not a valid S item of the banner (e.g. not in the provider catalog) */
    public const string INVALID_ITEM = 'invalid_item';
    /** A free-text item needs an item type the banner does not imply */
    public const string ITEM_TYPE_REQUIRED = 'item_type_required';
    /** The S (or the anchor S) comes from an official S-rank history (origin official): its pity is not an estimate */
    public const string OFFICIAL_PULL = 'official_pull';

    /**
     * @param string $violation Machine-readable code exposed to the client (one of the constants above)
     */
    public function __construct(
        public readonly string $violation,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
