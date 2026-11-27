<?php

namespace App\CoreBundle\Model\Chat;

/**
 * A fixed choice of an option, or an autocomplete suggestion: what the user sees and the value the command gets.
 */
final readonly class ChatChoice
{
    public function __construct(
        public string $name,
        public string|int $value,
    ) {
    }
}
