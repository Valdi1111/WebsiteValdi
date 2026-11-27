<?php

namespace App\Tests\GamesBundle\Chat\Fixtures;

use App\CoreBundle\Entity\Role;
use App\CoreBundle\Entity\User;
use App\CoreBundle\Entity\UserRole;
use App\CoreBundle\Service\UserRoleResolver;
use App\GamesBundle\Model\Game\CheckInStatus;
use App\GamesBundle\Service\Profile\GameProfileRegistry;
use App\Tests\GamesBundle\Status\Fixtures\FakeCheckInGameService;
use App\Tests\GamesBundle\Status\Fixtures\OtherFakeGameProfile;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The game profiles of the chat command tests, put in the test container in place of the real providers; the
 * collector of the container then reads them with the real sections.
 *
 * - provider "fake" (ROLE_USER_FAKE): "full_game" (slug "full", every section), "checkin_game" (slug "ci", the
 *   check-in only, as HI3 or ToT), "plain_game" (slug "plain", no section, as NTE);
 * - provider "other" (ROLE_USER_OTHER): "other_game" (slug "og", every section).
 */
final class GamesChatWorld
{
    public const string ROLE_GAMES = 'ROLE_USER_GAMES';
    public const string ROLE_FAKE = 'ROLE_USER_FAKE';
    public const string ROLE_OTHER = 'ROLE_USER_OTHER';

    public ChatProfileProvider $fake;
    public ChatProfileProvider $other;
    public FakeChatGameService $full;
    public FakeCheckInGameService $checkIn;
    public FakeChatGameService $otherGame;

    public function __construct()
    {
        // Five minutes old: the notes come from the cache
        $fetchedAt = new \DateTimeImmutable('@' . (time() - 300));
        $this->full = new FakeChatGameService($fetchedAt);
        $this->checkIn = new FakeCheckInGameService(new CheckInStatus(false, 3, $fetchedAt));
        $this->otherGame = new FakeChatGameService($fetchedAt);

        $this->fake = new ChatProfileProvider('fake', ChatGameProfile::class);
        $this->fake->services = ['full_game' => $this->full, 'checkin_game' => $this->checkIn, 'plain_game' => new \stdClass()];
        $this->fake->slugs = ['full_game' => ['full', ['fullgame']], 'checkin_game' => ['ci', ['checkin']], 'plain_game' => ['plain']];

        $this->other = new ChatProfileProvider('other', OtherFakeGameProfile::class);
        $this->other->services = ['other_game' => $this->otherGame];
        $this->other->slugs = ['other_game' => ['og']];
    }

    /**
     * Puts the registry in the container, before anything builds the collector, the selector or the commands.
     */
    public function install(ContainerInterface $container): GameProfileRegistry
    {
        $registry = new GameProfileRegistry(
            [$this->fake, $this->other],
            $container->get(UserRoleResolver::class),
            ['fake' => self::ROLE_FAKE, 'other' => self::ROLE_OTHER],
        );
        $container->set(GameProfileRegistry::class, $registry);
        // No refresh counted by another test or run
        $container->get('cache.rate_limiter')->clear();

        return $registry;
    }

    /**
     * A user with these roles assigned, linked on Discord ("d<id>").
     *
     * @param list<string> $roles
     */
    public static function user(int $id, array $roles = [self::ROLE_GAMES, self::ROLE_FAKE]): User
    {
        $user = new User()->setEmail(sprintf('user%d@example.test', $id))->setPassword('$2y$04$hash')->setDiscordUserId('d' . $id);
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);
        foreach ($roles as $role) {
            $user->addAssignedRole(new UserRole()->setRole(new Role()->setName($role)));
        }

        return $user;
    }

    /**
     * A profile of the "fake" provider, saved in it.
     */
    public function profile(User $owner, int $id, string $gameBiz = 'full_game', ?string $nickname = null, ?string $uid = null): ChatGameProfile
    {
        $profile = ChatGameProfile::ownedBy($owner, $id, $gameBiz, accountId: (int) $owner->getId())->named($nickname, $uid);
        $this->fake->profiles[] = $profile;

        return $profile;
    }

    /**
     * A profile of the "other" provider, saved in it.
     */
    public function otherProfile(User $owner, int $id): OtherFakeGameProfile
    {
        $profile = new OtherFakeGameProfile(ChatGameProfile::ownedBy($owner, $id, 'other_game', accountId: (int) $owner->getId()));
        $this->other->profiles[] = $profile;

        return $profile;
    }
}
