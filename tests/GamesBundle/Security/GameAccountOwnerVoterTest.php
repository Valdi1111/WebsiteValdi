<?php

namespace App\Tests\GamesBundle\Security;

use App\CoreBundle\Entity\User;
use App\GamesBundle\Model\Game\GameAccountInterface;
use App\GamesBundle\Security\GameAccountOwnerVoter;
use App\GryphlineBundle\Entity\GryphlineAccount;
use App\HoyoverseBundle\Entity\HoyoverseAccount;
use App\PerfectworldBundle\Entity\PerfectworldAccount;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

class GameAccountOwnerVoterTest extends TestCase
{
    private static function createUser(?int $id): User
    {
        $user = new User();
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);

        return $user;
    }

    private static function vote(User $user, mixed $subject, string $attribute = GameAccountOwnerVoter::OWNER): int
    {
        $token = new UsernamePasswordToken($user, 'main', ['ROLE_USER']);

        return (new GameAccountOwnerVoter())->vote($token, $subject, [$attribute]);
    }

    /**
     * @return iterable<string, array{class-string<GameAccountInterface>}>
     */
    public static function accountClassProvider(): iterable
    {
        yield 'hoyoverse' => [HoyoverseAccount::class];
        yield 'gryphline' => [GryphlineAccount::class];
        yield 'perfectworld' => [PerfectworldAccount::class];
    }

    /**
     * @param class-string<HoyoverseAccount|GryphlineAccount|PerfectworldAccount> $accountClass
     */
    #[DataProvider('accountClassProvider')]
    public function testOwnerIsGrantedEvenWithAnotherInstanceOfTheSameUser(string $accountClass): void
    {
        $account = (new $accountClass())->setUser(self::createUser(1));

        $this->assertSame(VoterInterface::ACCESS_GRANTED, self::vote(self::createUser(1), $account));
    }

    /**
     * @param class-string<HoyoverseAccount|GryphlineAccount|PerfectworldAccount> $accountClass
     */
    #[DataProvider('accountClassProvider')]
    public function testOtherUserIsDenied(string $accountClass): void
    {
        $account = (new $accountClass())->setUser(self::createUser(1));

        $this->assertSame(VoterInterface::ACCESS_DENIED, self::vote(self::createUser(2), $account));
    }

    public function testAccountWithoutOwnerOrUnsavedUsersAreDenied(): void
    {
        $this->assertSame(VoterInterface::ACCESS_DENIED, self::vote(self::createUser(1), new GryphlineAccount()));
        $this->assertSame(
            VoterInterface::ACCESS_DENIED,
            self::vote(self::createUser(null), (new GryphlineAccount())->setUser(self::createUser(null))),
            'Two users without id must not match each other',
        );
    }

    public function testAnonymousTokenIsDenied(): void
    {
        $account = (new HoyoverseAccount())->setUser(self::createUser(1));

        $this->assertSame(VoterInterface::ACCESS_DENIED, (new GameAccountOwnerVoter())->vote(new NullToken(), $account, [GameAccountOwnerVoter::OWNER]));
    }

    public function testOtherAttributesAndSubjectsAreIgnored(): void
    {
        $account = (new PerfectworldAccount())->setUser(self::createUser(1));

        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, self::vote(self::createUser(1), $account, 'ROLE_USER'));
        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, self::vote(self::createUser(1), new \stdClass()));
    }
}
