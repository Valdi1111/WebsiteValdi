<?php

namespace App\Tests\GamesBundle\Status;

use App\CoreBundle\Entity\User;
use App\CoreBundle\Service\UserRoleResolver;
use App\GamesBundle\Model\Profile\GameProfileRef;
use App\GamesBundle\Service\Profile\GameProfileRegistry;
use App\Tests\GamesBundle\Status\Fixtures\FakeGameProfile;
use App\Tests\GamesBundle\Status\Fixtures\FakeProfileProvider;
use App\Tests\GamesBundle\Status\Fixtures\OtherFakeGameProfile;
use PHPUnit\Framework\TestCase;

class GameProfileRegistryTest extends TestCase
{
    private FakeProfileProvider $fake;
    private FakeProfileProvider $other;

    /** @var array<int, list<string>> user id => reachable roles */
    private array $roles = [];

    protected function setUp(): void
    {
        $this->fake = new FakeProfileProvider('fake', FakeGameProfile::class);
        $this->fake->services = ['fake_game' => new \stdClass(), 'second_game' => new \stdClass()];
        $this->other = new FakeProfileProvider('other', OtherFakeGameProfile::class);
        $this->other->services = ['other_game' => new \stdClass()];
        $this->other->slugs = ['other_game' => ['og', ['other']]];
    }

    public static function user(int $id): User
    {
        $user = new User()->setEmail(sprintf('user%d@example.test', $id));
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);

        return $user;
    }

    /**
     * @param array<string, string> $providerRoles
     */
    private function registry(array $providerRoles = ['other' => 'ROLE_OTHER', 'fake' => 'ROLE_FAKE']): GameProfileRegistry
    {
        $resolver = $this->createStub(UserRoleResolver::class);
        $resolver->method('getReachableRoleNames')->willReturnCallback(fn (User $user): array => $this->roles[$user->getId()] ?? []);

        return new GameProfileRegistry([$this->fake, $this->other], $resolver, $providerRoles);
    }

    public function testProvidersFollowTheConfiguredOrderAndUnconfiguredOnesAreLeftOut(): void
    {
        $this->assertSame(['other', 'fake'], array_keys($this->registry()->getProviders()));
        $this->assertSame(['fake'], array_keys($this->registry(['fake' => 'ROLE_FAKE'])->getProviders()));
    }

    public function testOnlyProvidersWithTheRoleOfTheUserAreGranted(): void
    {
        $this->roles[1] = ['ROLE_FAKE', 'ROLE_USER'];

        $this->assertSame(['fake'], array_keys($this->registry()->getGrantedProviders(self::user(1))));
        $this->assertSame([], $this->registry()->getGrantedProviders(self::user(2)));
    }

    public function testProfilesOfTheUserByProviderThenAccountThenId(): void
    {
        $user = self::user(1);
        $this->roles[1] = ['ROLE_FAKE', 'ROLE_OTHER'];
        $this->fake->profiles = [
            FakeGameProfile::ownedBy($user, 5, accountId: 2),
            FakeGameProfile::ownedBy($user, 7, accountId: 1),
            FakeGameProfile::ownedBy($user, 3, accountId: 1),
            FakeGameProfile::ownedBy($user, 4, accountId: 1, active: false),
            FakeGameProfile::ownedBy(self::user(2), 9),
        ];
        $this->other->profiles = [new OtherFakeGameProfile(FakeGameProfile::ownedBy($user, 1, 'other_game'))];

        $ids = array_map(static fn ($profile): string => $profile::class . '#' . $profile->getId(), $this->registry()->findProfilesOf($user));

        $this->assertSame([OtherFakeGameProfile::class . '#1', FakeGameProfile::class . '#3', FakeGameProfile::class . '#7', FakeGameProfile::class . '#5'], $ids);
    }

    public function testProfilesOfOneGame(): void
    {
        $user = self::user(1);
        $this->roles[1] = ['ROLE_FAKE', 'ROLE_OTHER'];
        $this->fake->profiles = [FakeGameProfile::ownedBy($user, 1, 'fake_game'), FakeGameProfile::ownedBy($user, 2, 'second_game')];
        $this->other->profiles = [new OtherFakeGameProfile(FakeGameProfile::ownedBy($user, 1, 'other_game'))];
        $registry = $this->registry();

        // A game missing from the slug map gets a slug derived from its name
        $game = $registry->findGame('game-second-game', $user);
        $this->assertNotNull($game);

        $profiles = $registry->findProfilesOf($user, $game);

        $this->assertSame([2], array_map(static fn ($profile): ?int => $profile->getId(), $profiles));
    }

    public function testResolveChecksTheRoleAndTheOwner(): void
    {
        $owner = self::user(1);
        $this->fake->profiles = [FakeGameProfile::ownedBy($owner, 42)];
        $registry = $this->registry();

        $this->assertNull($registry->resolve($owner, new GameProfileRef('fake', 42)), 'provider not granted');

        $this->roles[1] = ['ROLE_FAKE'];
        $this->roles[2] = ['ROLE_FAKE'];
        // Another instance of the same user, as a chat request loads it
        $this->assertSame($this->fake->profiles[0], $registry->resolve(self::user(1), new GameProfileRef('fake', 42)));
        $this->assertNull($registry->resolve(self::user(2), new GameProfileRef('fake', 42)), 'profile of another user');
        $this->assertNull($registry->resolve($owner, new GameProfileRef('fake', 43)), 'missing profile');
        $this->assertNull($registry->resolve($owner, new GameProfileRef('unknown', 42)), 'unknown provider');
    }

    public function testProviderAndReferenceOfAProfile(): void
    {
        $registry = $this->registry();
        $profile = new OtherFakeGameProfile(FakeGameProfile::ownedBy(self::user(1), 8, 'other_game'));

        $this->assertSame($this->other, $registry->providerOf($profile));
        $this->assertSame('other:8', (string) $registry->refOf($profile));
        $this->assertSame('og', $registry->describe($profile)?->slug);

        $this->expectException(\InvalidArgumentException::class);
        $registry->refOf(new FakeGameProfile(null));
    }

    public function testGamesAreFoundBySlugOrAliasAmongTheGrantedProviders(): void
    {
        $this->roles[1] = ['ROLE_FAKE'];
        $registry = $this->registry();

        $this->assertSame('fake_game', $registry->findGame('FAKE')?->gameBiz);
        $this->assertSame('other_game', $registry->findGame('og')?->gameBiz);
        $this->assertNull($registry->findGame('og', self::user(1)), 'provider not granted to the user');
        $this->assertSame(['og', 'fg', 'game-second-game'], array_map(static fn ($game): string => $game->slug, $registry->getGames()));
    }
}
