<?php

namespace App\CoreBundle\Attribute;

/**
 * Handler of a chat button (ChatAction), on a class implementing ChatActionHandlerInterface. Autoconfigured with the tag
 * core.chat_action, indexed by $name: the first part of the encoded ChatActionRef ("name:arg1:arg2").
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class AsChatAction
{
    public const string TAG = 'core.chat_action';

    public function __construct(
        public string $name,
    ) {
    }
}
