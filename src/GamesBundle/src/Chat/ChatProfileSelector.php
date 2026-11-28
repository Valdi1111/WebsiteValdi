<?php

namespace App\GamesBundle\Chat;

use App\CoreBundle\Entity\User;
use App\CoreBundle\Model\Chat\ChatChoice;
use App\CoreBundle\Service\Chat\Discord\DiscordEmbedLimiter;
use App\GamesBundle\Model\Chat\ChatProfileSelection;
use App\GamesBundle\Model\Game\GameProfileInterface;
use App\GamesBundle\Model\Profile\GameDescriptor;
use App\GamesBundle\Model\Profile\GameProfileRef;
use App\GamesBundle\Service\Profile\GameProfileRegistry;
use App\GamesBundle\Service\Status\GameProfileStatusCollector;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The game and profile options of the game chat commands: which profiles a command shows, and the autocomplete
 * suggestions. Database only (the autocomplete runs within Discord's 3 seconds), always through GameProfileRegistry:
 * the provider roles and the ownership of every profile are checked there.
 *
 * Profiles whose support cannot be told without reading (unreadable cookie: no context) are kept everywhere, so the
 * answer can tell the user to log in again instead of hiding the profile.
 */
class ChatProfileSelector
{
    public const int MAX_CHOICES = 25;
    public const int MAX_CHOICE_NAME = 100;
    public const string NOT_FOUND = 'This profile is no longer among yours.';

    public function __construct(
        private readonly GameProfileRegistry $registry,
        private readonly GameProfileStatusCollector $collector,
        #[Autowire(param: 'games.domain_name')]
        private readonly string $gamesDomain,
    ) {
    }

    /**
     * The profiles of the options as typed: game by slug or alias, profile by reference ("hoyoverse:42"), UID or
     * nickname (ignoring case), narrowed by the game when both are given.
     *
     * @param bool $public whether the refusal may be seen by others (a public answer on Discord): it then names the
     *                     games only, never a nickname nor a UID
     *
     * @return ChatProfileSelection|string the selection, or the refusal for the user
     */
    public function select(User $user, ?string $game, ?string $profile, bool $public = false): ChatProfileSelection|string
    {
        $game = self::blankToNull($game);
        $profile = self::blankToNull($profile);

        $all = $this->registry->findProfilesOf($user);
        if ($all === []) {
            return sprintf('You have no active game profile yet. Add one on https://%s/', $this->gamesDomain);
        }

        $descriptor = null;
        if ($game !== null) {
            $descriptor = $this->registry->findGame($game, $user);
            if ($descriptor === null) {
                return sprintf('Unknown game "%s". Your games: %s.', self::shorten($game), implode(', ', $this->slugsOf($all)));
            }
        }

        if ($profile === null) {
            $profiles = $descriptor !== null ? array_values(array_filter($all, static fn (GameProfileInterface $candidate): bool => $candidate->getGameBiz() === $descriptor->gameBiz)) : $all;
            if ($profiles === []) {
                return sprintf('You have no active profile of %s.', $descriptor?->name);
            }

            return $this->selection($descriptor, null, $profiles);
        }

        $found = $this->findProfile($user, $all, $descriptor, $profile, $public);
        if (!$found instanceof GameProfileInterface) {
            return $found;
        }

        return $this->selection($this->registry->describe($found), $this->registry->refOf($found), [$found]);
    }

    /**
     * The selection a button carries, in the canonical form of ChatProfileSelection: a game slug, or a profile
     * reference split in provider and id ('' when absent).
     *
     * @return ChatProfileSelection|string
     */
    public function fromButton(User $user, string $slug, string $provider, string $id): ChatProfileSelection|string
    {
        if ($provider !== '' || $id !== '') {
            // Parsed, not built: a malformed provider or id ("Fake", "0") must be refused, not thrown
            $ref = GameProfileRef::tryFromString($provider . ':' . $id);

            return $ref !== null ? $this->select($user, null, (string) $ref) : self::NOT_FOUND;
        }

        return $this->select($user, $slug !== '' ? $slug : null, null);
    }

    /**
     * Games of the user's active profiles that may support the command's sections, by name, slug or alias.
     *
     * @param list<string>                                  $sectionKeys the command's sections; [] = any game
     * @param (\Closure(GameProfileInterface): bool)|null $accept      another filter on the profiles (e.g. those
     *                                                                   with a pull tracker); null = none
     * @return list<ChatChoice> value = slug
     */
    public function suggestGames(User $user, array $sectionKeys, string $partial, ?\Closure $accept = null): array
    {
        $games = [];
        foreach ($this->registry->findProfilesOf($user) as $profile) {
            $game = $this->registry->describe($profile);
            if ($game === null || isset($games[$game->slug]) || !$this->mayShow($profile, $sectionKeys) || ($accept !== null && !$accept($profile))) {
                continue;
            }
            if (self::matches($partial, $game->name, $game->slug, ...$game->aliases)) {
                $games[$game->slug] = new ChatChoice(DiscordEmbedLimiter::truncate($game->name, self::MAX_CHOICE_NAME), $game->slug);
            }
        }

        return array_slice(array_values($games), 0, self::MAX_CHOICES);
    }

    /**
     * Active profiles of the user that may support the command's sections, narrowed by the game already chosen.
     *
     * @param list<string>                                  $sectionKeys the command's sections; [] = any profile
     * @param (\Closure(GameProfileInterface): bool)|null $accept      another filter on the profiles; null = none
     * @return list<ChatChoice> name "Game · Nickname (UID)", value = profile reference
     */
    public function suggestProfiles(User $user, array $sectionKeys, ?string $game, string $partial, ?\Closure $accept = null): array
    {
        $descriptor = self::blankToNull($game) !== null ? $this->registry->findGame((string) $game, $user) : null;

        $choices = [];
        foreach ($this->registry->findProfilesOf($user, $descriptor) as $profile) {
            if (!$this->mayShow($profile, $sectionKeys) || ($accept !== null && !$accept($profile))) {
                continue;
            }
            $label = self::label($profile);
            if (self::matches($partial, $label)) {
                $choices[] = new ChatChoice(DiscordEmbedLimiter::truncate($label, self::MAX_CHOICE_NAME), (string) $this->registry->refOf($profile));
            }
            if (count($choices) === self::MAX_CHOICES) {
                break;
            }
        }

        return $choices;
    }

    /**
     * "Genshin Impact · Nickname (UID)": how a profile is named in the answers and the suggestions.
     */
    public static function label(GameProfileInterface $profile): string
    {
        return sprintf('%s · %s (%s)', $profile->getGameName() ?? 'Game', $profile->getNickname() ?? 'Player', $profile->getGameUid() ?? '?');
    }

    /**
     * @param list<GameProfileInterface> $all
     * @return GameProfileInterface|string the profile, or the refusal
     */
    private function findProfile(User $user, array $all, ?GameDescriptor $game, string $value, bool $public = false): GameProfileInterface|string
    {
        $ref = GameProfileRef::tryFromString($value);
        if ($ref !== null) {
            $profile = $this->registry->resolve($user, $ref);
            if ($profile === null || ($game !== null && $profile->getGameBiz() !== $game->gameBiz)) {
                return self::NOT_FOUND;
            }

            return $profile;
        }

        $matches = array_values(array_filter($all, static fn (GameProfileInterface $candidate): bool => ($game === null || $candidate->getGameBiz() === $game->gameBiz)
            && ($candidate->getGameUid() === $value || mb_strtolower((string) $candidate->getNickname()) === mb_strtolower($value))));
        if ($matches === []) {
            return sprintf('No profile "%s" among yours.', self::shorten($value));
        }
        if (count($matches) > 1) {
            // Never a guess: the same nickname is common across games
            if ($public) {
                return sprintf(
                    'Several profiles match "%s" (%s). Give the game too, or choose one in the profile option.',
                    self::shorten($value),
                    implode(', ', self::gameNames($matches)),
                );
            }

            return sprintf(
                'Several profiles match "%s": %s. Choose one by its UID, or give the game too.',
                self::shorten($value),
                implode('; ', array_map(self::label(...), $matches)),
            );
        }

        return $matches[0];
    }

    /**
     * The games of the profiles, each once, in their order: how a public answer names several profiles.
     *
     * @param list<GameProfileInterface> $profiles
     * @return list<string>
     */
    public static function gameNames(array $profiles): array
    {
        return array_values(array_unique(array_map(static fn (GameProfileInterface $profile): string => $profile->getGameName() ?? 'Game', $profiles)));
    }

    /**
     * @param list<GameProfileInterface> $profiles
     */
    private function selection(?GameDescriptor $game, ?GameProfileRef $profile, array $profiles): ChatProfileSelection
    {
        return new ChatProfileSelection($game, $profile, $profiles, array_map($this->registry->refOf(...), $profiles));
    }

    /**
     * Whether the profile may have something to show for these sections: true when unknown (no context), so an
     * unreadable profile is never hidden.
     *
     * @param list<string> $sectionKeys [] = any profile
     */
    public function mayShow(GameProfileInterface $profile, array $sectionKeys): bool
    {
        if ($sectionKeys === []) {
            return true;
        }
        $supported = $this->collector->findSupportedSectionKeys($profile);

        return $supported === null || array_intersect($sectionKeys, $supported) !== [];
    }

    /**
     * @param list<GameProfileInterface> $profiles
     * @return list<string>
     */
    private function slugsOf(array $profiles): array
    {
        $slugs = [];
        foreach ($profiles as $profile) {
            $slug = $this->registry->describe($profile)?->slug;
            if ($slug !== null) {
                $slugs[$slug] = $slug;
            }
        }

        return array_values($slugs);
    }

    private static function matches(string $partial, string ...$candidates): bool
    {
        $partial = mb_strtolower(trim($partial));
        if ($partial === '') {
            return true;
        }
        foreach ($candidates as $candidate) {
            if (str_contains(mb_strtolower($candidate), $partial)) {
                return true;
            }
        }

        return false;
    }

    private static function blankToNull(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : trim($value);
    }

    /**
     * What the user typed, repeated in an answer only within reason.
     */
    private static function shorten(string $value): string
    {
        return DiscordEmbedLimiter::truncate($value, 50);
    }
}
