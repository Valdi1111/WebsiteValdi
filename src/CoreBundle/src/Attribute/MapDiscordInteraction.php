<?php

namespace App\CoreBundle\Attribute;

use App\CoreBundle\ValueResolver\DiscordInteractionValueResolver;
use Symfony\Component\HttpKernel\Attribute\ValueResolver;

/**
 * On a DiscordInteraction controller argument: the request body, once its Ed25519 signature is verified
 * (DiscordInteractionValueResolver). Like #[MapRequestPayload], but the raw body must be checked before it is parsed.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final class MapDiscordInteraction extends ValueResolver
{
    public function __construct()
    {
        parent::__construct(DiscordInteractionValueResolver::class);
    }
}
