<?php

namespace App\GamesBundle\Service\Pull\Card;

use App\GamesBundle\Model\Game\GameProfileInterface;
use App\GamesBundle\Model\Pull\Card\PullCardBuilderInterface;
use App\GamesBundle\Service\Profile\GameProfileRegistry;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * The pulls card builder of a profile, by the key of its provider.
 */
class PullCardBuilderRegistry
{
    /** @var array<string, PullCardBuilderInterface> by provider key */
    private array $builders = [];

    /**
     * @param iterable<PullCardBuilderInterface> $builders
     */
    public function __construct(
        #[AutowireIterator(PullCardBuilderInterface::TAG)]
        iterable $builders,
        private readonly GameProfileRegistry $profiles,
    ) {
        foreach ($builders as $builder) {
            $this->builders[$builder::getProviderKey()] = $builder;
        }
    }

    /**
     * The builder of the profile, null when its provider has none or its game has no pull tracker.
     */
    public function find(GameProfileInterface $profile): ?PullCardBuilderInterface
    {
        $provider = $this->profiles->providerOf($profile);
        $builder = $provider !== null ? $this->builders[$provider->getKey()] ?? null : null;

        return $builder !== null && $builder->supports($profile) ? $builder : null;
    }
}
