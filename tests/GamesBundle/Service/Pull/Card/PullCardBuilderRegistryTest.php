<?php

namespace App\Tests\GamesBundle\Service\Pull\Card;

use App\CoreBundle\Entity\User;
use App\CoreBundle\Service\UserRoleResolver;
use App\GamesBundle\Model\Game\GameProfileInterface;
use App\GamesBundle\Service\Profile\GameProfileRegistry;
use App\GamesBundle\Service\Pull\Card\PullCardBuilderRegistry;
use App\Tests\GamesBundle\Chat\Fixtures\ChatGameProfile;
use App\Tests\GamesBundle\Chat\Fixtures\ChatProfileProvider;
use App\Tests\GamesBundle\Service\Pull\Card\Fixtures\FakePullCardBuilder;
use App\Tests\GamesBundle\Status\Fixtures\FakeGameProfile;
use App\Tests\GamesBundle\Status\Fixtures\OtherFakeGameProfile;
use PHPUnit\Framework\TestCase;

/**
 * The pulls card builder of a profile: the one of its provider, only when it supports the profile.
 */
class PullCardBuilderRegistryTest extends TestCase
{
    private FakePullCardBuilder $fakeBuilder;
    private FakePullCardBuilder $otherBuilder;
    private PullCardBuilderRegistry $registry;

    protected function setUp(): void
    {
        // "third" has no builder; a FakeGameProfile is not a ChatGameProfile, so it belongs to "third" only
        $providers = [
            new ChatProfileProvider('fake', ChatGameProfile::class),
            new ChatProfileProvider('other', OtherFakeGameProfile::class),
            new ChatProfileProvider('third', FakeGameProfile::class),
        ];
        $profiles = new GameProfileRegistry(
            $providers,
            $this->createStub(UserRoleResolver::class),
            ['fake' => 'ROLE_USER_FAKE', 'other' => 'ROLE_USER_OTHER', 'third' => 'ROLE_USER_THIRD'],
        );

        $this->fakeBuilder = new FakePullCardBuilder(['full_game']);
        // Claims the games of "fake" too: never asked for the profiles of another provider
        $this->otherBuilder = new class(['other_game', 'full_game', 'plain_game']) extends FakePullCardBuilder {
            public static function getProviderKey(): string
            {
                return 'other';
            }
        };

        $this->registry = new PullCardBuilderRegistry([$this->fakeBuilder, $this->otherBuilder], $profiles);
    }

    public function testBuilderOfTheProviderOfTheProfile(): void
    {
        $owner = new User();

        $this->assertSame($this->fakeBuilder, $this->registry->find(ChatGameProfile::ownedBy($owner, 1, 'full_game')));
        $this->assertSame($this->otherBuilder, $this->registry->find(new OtherFakeGameProfile(ChatGameProfile::ownedBy($owner, 2, 'other_game'))));
    }

    public function testNoBuilderWhenTheBuilderOfTheProviderDoesNotSupportTheProfile(): void
    {
        // The builder of "other" supports plain_game, but the profile is one of "fake"
        $this->assertNull($this->registry->find(ChatGameProfile::ownedBy(new User(), 1, 'plain_game')));
        $this->assertNull($this->registry->find(new OtherFakeGameProfile(ChatGameProfile::ownedBy(new User(), 2, 'checkin_game'))));
    }

    public function testNoBuilderForAProviderWithoutOneOrAnUnknownProvider(): void
    {
        // Provider "third" is registered but has no builder
        $this->assertNull($this->registry->find(new FakeGameProfile(3, 'full_game')));
        // No provider at all for this profile class
        $this->assertNull($this->registry->find($this->createStub(GameProfileInterface::class)));
    }

    public function testBuilderOfAProviderLeftOutOfTheConfigurationIsNeverFound(): void
    {
        // "other" is not in games.providers: its profiles have no provider, so no builder
        $profiles = new GameProfileRegistry(
            [new ChatProfileProvider('fake', ChatGameProfile::class), new ChatProfileProvider('other', OtherFakeGameProfile::class)],
            $this->createStub(UserRoleResolver::class),
            ['fake' => 'ROLE_USER_FAKE'],
        );
        $registry = new PullCardBuilderRegistry([$this->fakeBuilder, $this->otherBuilder], $profiles);

        $this->assertNull($registry->find(new OtherFakeGameProfile(ChatGameProfile::ownedBy(new User(), 2, 'other_game'))));
        $this->assertSame($this->fakeBuilder, $registry->find(ChatGameProfile::ownedBy(new User(), 1, 'full_game')));
    }
}
