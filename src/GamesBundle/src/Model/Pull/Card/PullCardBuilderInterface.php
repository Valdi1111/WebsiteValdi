<?php

namespace App\GamesBundle\Model\Pull\Card;

use App\GamesBundle\Model\Game\GameProfileInterface;

/**
 * Builds the shareable pulls card of the profiles of one provider (tag games.pull_card_builder, see GamesBundle), for
 * the callers that only know a GameProfileInterface: the chat command /pulls.
 */
interface PullCardBuilderInterface
{
    public const string TAG = 'games.pull_card_builder';

    /**
     * Key of the provider of the profiles (GameProfileProviderInterface::getKey()).
     */
    public static function getProviderKey(): string;

    /**
     * Whether the profile is one of the provider's and its game has a pull tracker.
     */
    public function supports(GameProfileInterface $profile): bool;

    /**
     * The banners configured for the game of the profile, in their order. Read from the configuration only (no
     * database): fit for an autocomplete. A banner missing from the configuration can still be asked for by its key.
     *
     * @return list<array{key: string, name: string}>
     */
    public function getBanners(GameProfileInterface $profile): array;

    /**
     * @param ?string $bannerKey  the banner of the card, null for every banner with pulls
     * @param bool    $showAll    every S pull instead of the newest ones
     * @param bool    $showPlayer whether the nickname and the UID appear on the card
     *
     * @throws \InvalidArgumentException when the profile is not supported or the banner is unknown
     */
    public function buildCard(GameProfileInterface $profile, ?string $bannerKey = null, bool $showAll = false, bool $showPlayer = false): PullCard;
}
