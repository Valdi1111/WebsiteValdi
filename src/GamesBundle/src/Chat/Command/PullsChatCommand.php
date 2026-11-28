<?php

namespace App\GamesBundle\Chat\Command;

use App\CoreBundle\Attribute\AsChatCommand;
use App\CoreBundle\Model\Chat\ChatAutocompleteInterface;
use App\CoreBundle\Model\Chat\ChatChoice;
use App\CoreBundle\Model\Chat\ChatCommandBuilder;
use App\CoreBundle\Model\Chat\ChatCommandInterface;
use App\CoreBundle\Model\Chat\ChatImage;
use App\CoreBundle\Model\Chat\ChatOptionType;
use App\CoreBundle\Model\Chat\ChatReply;
use App\CoreBundle\Model\Chat\ChatRequest;
use App\CoreBundle\Model\Chat\ChatUser;
use App\CoreBundle\Service\Chat\Discord\DiscordEmbedLimiter;
use App\GamesBundle\Chat\ChatProfileSelector;
use App\GamesBundle\Exception\PullCardUnavailableException;
use App\GamesBundle\Exception\TooManyPullCardsException;
use App\GamesBundle\Model\Game\GameProfileInterface;
use App\GamesBundle\Model\Pull\Card\PullCardBuilderInterface;
use App\GamesBundle\Service\Pull\Card\PullCardBuilderRegistry;
use App\GamesBundle\Service\Pull\Card\PullCardLimiter;
use App\GamesBundle\Service\Pull\Card\PullCardRenderer;

/**
 * /pulls: the shareable image of the pulls of one game profile (#59), the card of the site as a WebP. Public on
 * Discord: it is made to be shown.
 *
 * The profile comes from the game and profile options like the other game commands, but it must be exactly one with
 * a pull tracker; without options, the only such profile of the user. The banner is a key or a name (Telegram has no
 * autocomplete), none for every banner with pulls. The cards share the limit of the site (PullCardLimiter), counted
 * once the card is built: a mistyped banner costs nothing.
 */
#[AsChatCommand(name: 'pulls', description: 'Show the image of the pulls of a game profile', role: AbstractProfileChatCommand::ROLE, ephemeral: false)]
class PullsChatCommand implements ChatCommandInterface, ChatAutocompleteInterface
{
    public const string OPTION_GAME = AbstractProfileChatCommand::OPTION_GAME;
    public const string OPTION_PROFILE = AbstractProfileChatCommand::OPTION_PROFILE;
    public const string OPTION_BANNER = 'banner';
    public const string OPTION_ALL = 'all';
    public const string OPTION_PLAYER = 'player';

    public function __construct(
        private readonly ChatProfileSelector $selector,
        private readonly PullCardBuilderRegistry $builders,
        private readonly PullCardRenderer $renderer,
        private readonly PullCardLimiter $limiter,
    ) {
    }

    public function configure(ChatCommandBuilder $builder): void
    {
        $builder
            ->addOption(self::OPTION_GAME, 'Only the profiles of this game', autocomplete: true)
            ->addOption(self::OPTION_PROFILE, 'Only this profile', autocomplete: true)
            ->addOption(self::OPTION_BANNER, 'Only this banner (all of them when empty)', autocomplete: true)
            ->addOption(self::OPTION_ALL, 'Show every S pull, not only the newest ones', ChatOptionType::Boolean)
            ->addOption(self::OPTION_PLAYER, 'Show the nickname and the UID', ChatOptionType::Boolean);
    }

    public function execute(ChatRequest $request): ChatReply
    {
        $found = $this->findProfile($request->user, self::stringOrNull($request->get(self::OPTION_GAME)), self::stringOrNull($request->get(self::OPTION_PROFILE)));
        if (is_string($found)) {
            return ChatReply::error($found);
        }
        [$profile, $builder] = $found;

        $bannerKey = null;
        $banner = self::stringOrNull($request->get(self::OPTION_BANNER));
        if ($banner !== null) {
            $bannerKey = self::findBannerKey($builder->getBanners($profile), $banner);
            if ($bannerKey === null) {
                return ChatReply::error(sprintf(
                    'Unknown banner "%s". Banners of %s: %s.',
                    mb_substr($banner, 0, 50),
                    $profile->getGameName() ?? 'this game',
                    implode(', ', array_column($builder->getBanners($profile), 'name')),
                ));
            }
        }

        try {
            $card = $builder->buildCard($profile, $bannerKey, $request->get(self::OPTION_ALL) === true, $request->get(self::OPTION_PLAYER) === true);
        } catch (\InvalidArgumentException $e) {
            // An unknown banner typed as a key gets the list; a profile changed meanwhile gets the reason alone
            return ChatReply::error(str_starts_with($e->getMessage(), 'Unknown banner') ? sprintf('%s Banners of %s: %s.', $e->getMessage(), $profile->getGameName() ?? 'this game', implode(', ', array_column($builder->getBanners($profile), 'name'))) : $e->getMessage());
        }

        try {
            // Counted once the card is known to exist (a mistyped banner costs nothing), before the render it guards
            $this->limiter->consume($request->user->user);
            $image = $this->renderer->renderWebp($card);
        } catch (TooManyPullCardsException|PullCardUnavailableException $e) {
            return ChatReply::error($e->getMessage());
        }

        $title = sprintf('%s · %s', $card->gameName, $card->title);

        return ChatReply::image(
            new ChatImage($this->renderer->filename($card, $image->extension), $image->mimeType, $image->content, $image->width, $image->height, $title),
            $title,
        );
    }

    public function autocomplete(ChatUser $user, ?string $subcommand, string $option, string $partial, array $arguments): array
    {
        $accept = fn (GameProfileInterface $profile): bool => $this->builders->find($profile) !== null;

        return match ($option) {
            self::OPTION_GAME => $this->selector->suggestGames($user->user, [], $partial, $accept),
            self::OPTION_PROFILE => $this->selector->suggestProfiles($user->user, [], self::stringOrNull($arguments[self::OPTION_GAME] ?? null), $partial, $accept),
            self::OPTION_BANNER => $this->suggestBanners($user, $arguments, $partial),
            default => [],
        };
    }

    /**
     * The one profile with a pull tracker the options point to.
     *
     * @return array{0: GameProfileInterface, 1: PullCardBuilderInterface}|string the profile and its builder, or the
     *                                                                             refusal for the user
     */
    private function findProfile(ChatUser $user, ?string $game, ?string $profile): array|string
    {
        // Public answer on Discord: the refusals name the games, never a nickname nor a UID
        $selection = $this->selector->select($user->user, $game, $profile, public: true);
        if (is_string($selection)) {
            return $selection;
        }

        $found = [];
        foreach ($selection->profiles as $candidate) {
            $builder = $this->builders->find($candidate);
            if ($builder !== null) {
                $found[] = [$candidate, $builder];
            }
        }

        if ($found === []) {
            return $profile !== null ? 'This profile has no pulls tracker.' : 'None of your profiles has a pulls tracker.';
        }
        if (count($found) > 1) {
            return sprintf(
                'Several of your profiles have pulls (%s). Choose one with the game or profile option.',
                implode(', ', ChatProfileSelector::gameNames(array_column($found, 0))),
            );
        }

        return $found[0];
    }

    /**
     * Banners of the profile already chosen, or of the only one the options point to.
     *
     * @param array<string, string|int|bool> $arguments
     *
     * @return list<ChatChoice>
     */
    private function suggestBanners(ChatUser $user, array $arguments, string $partial): array
    {
        $found = $this->findProfile($user, self::stringOrNull($arguments[self::OPTION_GAME] ?? null), self::stringOrNull($arguments[self::OPTION_PROFILE] ?? null));
        if (is_string($found)) {
            return [];
        }
        [$profile, $builder] = $found;

        $choices = [];
        $partial = mb_strtolower(trim($partial));
        foreach ($builder->getBanners($profile) as ['key' => $key, 'name' => $name]) {
            if ($partial === '' || str_contains(mb_strtolower($name), $partial) || str_contains(mb_strtolower($key), $partial)) {
                $choices[] = new ChatChoice(DiscordEmbedLimiter::truncate($name, ChatProfileSelector::MAX_CHOICE_NAME), $key);
            }
        }

        return array_slice($choices, 0, ChatProfileSelector::MAX_CHOICES);
    }

    /**
     * The key of the banner typed: by key, by name (ignoring case), or by the part of one name only ("character"). A
     * key missing from the configuration (a banner of its own in the overview) is kept as typed: the builder refuses
     * it when unknown.
     *
     * @param list<array{key: string, name: string}> $banners
     */
    private static function findBannerKey(array $banners, string $typed): ?string
    {
        $typed = trim($typed);
        $lower = mb_strtolower($typed);
        $partial = [];
        foreach ($banners as ['key' => $key, 'name' => $name]) {
            if (strcasecmp($key, $typed) === 0 || mb_strtolower($name) === $lower) {
                return $key;
            }
            if (str_contains(mb_strtolower($name), $lower)) {
                $partial[] = $key;
            }
        }
        if (count($partial) === 1) {
            return $partial[0];
        }

        // An unconfigured type looks like a key: digits or a pool type, never spaces
        return preg_match('/^[A-Za-z0-9_]{1,64}$/D', $typed) === 1 ? $typed : null;
    }

    private static function stringOrNull(string|int|bool|null $value): ?string
    {
        if ($value === null || is_bool($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
