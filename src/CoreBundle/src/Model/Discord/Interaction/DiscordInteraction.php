<?php

namespace App\CoreBundle\Model\Discord\Interaction;

/**
 * An interaction Discord posts to /discord/interactions (https://discord.com/developers/docs/interactions/receiving-and-responding),
 * denormalized by DiscordInteractionValueResolver once its signature is verified. Only the fields the bot reads.
 *
 * Every field but the type is optional here: which ones an interaction carries depends on its type (a PING has no
 * user nor data), so DiscordInteractionHandler checks them per type.
 */
final readonly class DiscordInteraction
{
    public const int TYPE_PING = 1;
    public const int TYPE_APPLICATION_COMMAND = 2;
    public const int TYPE_MESSAGE_COMPONENT = 3;
    public const int TYPE_APPLICATION_COMMAND_AUTOCOMPLETE = 4;

    /**
     * @param string|null $token    lasts 15 minutes and lets anyone answer the interaction: a credential, never logged
     * @param string|null $locale   language of the user's client (e.g. "it", "en-US")
     * @param string|null $guildId  null in a direct message or where the application is installed by the user
     * @param DiscordInteractionMember|null $member who sent it, in a server
     * @param DiscordInteractionUser|null   $user   who sent it, in a direct message
     */
    public function __construct(
        public int $type,
        public ?string $id = null,
        public ?string $applicationId = null,
        #[\SensitiveParameter]
        public ?string $token = null,
        public ?string $locale = null,
        public ?DiscordInteractionData $data = null,
        public ?DiscordInteractionMember $member = null,
        public ?DiscordInteractionUser $user = null,
        public ?string $guildId = null,
    ) {
    }

    /**
     * Discord gives the user under "member" in a server and under "user" elsewhere.
     */
    public function getUserId(): ?string
    {
        $id = $this->member?->user?->id ?? $this->user?->id;

        return $id === null || $id === '' ? null : $id;
    }

    /**
     * Language of the answer: the first two letters of the client locale ("en-US" → "en"), "en" when unknown.
     */
    public function getLanguage(): string
    {
        $language = strtolower(substr($this->locale ?? '', 0, 2));

        return preg_match('/^[a-z]{2}$/', $language) === 1 ? $language : 'en';
    }
}
