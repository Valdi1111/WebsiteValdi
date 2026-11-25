<?php

namespace App\Tests\GamesBundle\Status;

use App\GamesBundle\Service\Profile\GameProfileRegistry;
use App\GamesBundle\Service\Status\GameProfileStatusCollector;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The profile status layer as the container builds it: providers, games and sections of the real bundles.
 */
class ProfileStatusWiringTest extends KernelTestCase
{
    public function testProvidersFollowTheGamesTabs(): void
    {
        self::bootKernel();

        $registry = static::getContainer()->get(GameProfileRegistry::class);

        $this->assertSame(['hoyoverse', 'gryphline', 'perfectworld'], array_keys($registry->getProviders()));
    }

    /**
     * Every game service gets a descriptor from its provider; a new one must be given a slug on purpose (a derived
     * slug fails here), and no slug or alias may be shared by two games.
     */
    public function testEveryGameHasItsOwnSlug(): void
    {
        self::bootKernel();

        $slugs = [];
        $names = [];
        foreach (static::getContainer()->get(GameProfileRegistry::class)->getGames() as $game) {
            $slugs[$game->provider][$game->gameBiz] = $game->slug;
            array_push($names, $game->slug, ...$game->aliases);
        }

        array_walk($slugs, static fn (array &$games): bool => ksort($games));

        $this->assertSame([
            'hoyoverse' => ['bh3_global' => 'hi3', 'hk4e_global' => 'gi', 'hkrpg_global' => 'hsr', 'nap_global' => 'zzz', 'nxx_global' => 'tot'],
            'gryphline' => ['endfield' => 'ake'],
            'perfectworld' => ['nte' => 'nte'],
        ], $slugs);
        $this->assertSame(array_unique($names), $names, 'slugs and aliases are unique across providers');
    }

    public function testSectionsInDisplayOrder(): void
    {
        self::bootKernel();

        $this->assertSame(
            ['stamina', 'dailies', 'weeklies', 'expeditions', 'realm_currency', 'shop_status'],
            array_keys(static::getContainer()->get(GameProfileStatusCollector::class)->getSections())
        );
    }

    /**
     * Section icons start the labels of games:status: an emoji made wide by the variation selector U+FE0F (🗓️, ⚔️)
     * is counted one column narrow by the console tables and runs into the label.
     */
    public function testSectionIconsAreWideWithoutVariationSelector(): void
    {
        self::bootKernel();

        foreach (static::getContainer()->get(GameProfileStatusCollector::class)->getSections() as $key => $section) {
            $this->assertStringNotContainsString("\u{FE0F}", $section->getIcon(), sprintf('Icon of the "%s" section', $key));
            $this->assertSame(1, mb_strlen($section->getIcon()), sprintf('Icon of the "%s" section', $key));
        }
    }
}
