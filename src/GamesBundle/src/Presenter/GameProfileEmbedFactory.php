<?php

namespace App\GamesBundle\Presenter;

use App\CoreBundle\Model\Notification\UniversalEmbed;
use App\GamesBundle\Model\Game\GameProfileInterface;
use Psr\Clock\ClockInterface;

/**
 * Header shared by the game profile embeds: "<game> - <title>", the game footer, and the player, region and rank
 * fields. The presenters add their data fields after these three.
 */
class GameProfileEmbedFactory
{
    public const int COLOR = 0x3498DB;

    public function __construct(
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Time of the embeds: presenters compute "time left" from it, so an embed never mixes two clocks.
     */
    public function now(): \DateTimeImmutable
    {
        return $this->clock->now();
    }

    public function create(GameProfileInterface $profile, string $title, ?string $description = null): UniversalEmbed
    {
        return new UniversalEmbed()
            ->setTitle(sprintf('%s - %s', $profile->getGameName(), $title))
            ->setDescription($description)
            ->setColor(self::COLOR)
            ->setFooterText($profile->getGameName())
            ->setFooterIconUrl($profile->getIconUrl() ?? $profile->getProviderIconUrl())
            ->setTimestamp($this->clock->now())
            ->addField('Player', sprintf('(`%s`) %s', $profile->getGameUid(), $profile->getNickname() ?? 'Player'), icon: '👤', inline: true)
            ->addField('Region', $profile->getParsedRegion() ?? 'Global', icon: '🌍', inline: true)
            ->addField('Rank', (string) ($profile->getLevel() ?? 1), icon: '🏆', inline: true);
    }
}
