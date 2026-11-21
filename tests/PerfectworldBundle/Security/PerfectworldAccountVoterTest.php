<?php

namespace App\Tests\PerfectworldBundle\Security;

use App\CoreBundle\Entity\User;
use App\PerfectworldBundle\Entity\PerfectworldAccount;
use App\PerfectworldBundle\Security\PerfectworldAccountVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

class PerfectworldAccountVoterTest extends TestCase
{
    private static function createUser(int $id): User
    {
        $user = new User();
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);

        return $user;
    }

    private static function vote(User $user, mixed $subject, string $attribute = PerfectworldAccountVoter::OWNER): int
    {
        $token = new UsernamePasswordToken($user, 'main', ['ROLE_USER']);

        return (new PerfectworldAccountVoter())->vote($token, $subject, [$attribute]);
    }

    public function testOwnerIsGranted(): void
    {
        $account = (new PerfectworldAccount())->setUser(self::createUser(1));

        $this->assertSame(VoterInterface::ACCESS_GRANTED, self::vote(self::createUser(1), $account));
    }

    public function testOtherUserIsDenied(): void
    {
        $account = (new PerfectworldAccount())->setUser(self::createUser(1));

        $this->assertSame(VoterInterface::ACCESS_DENIED, self::vote(self::createUser(2), $account));
    }

    public function testOtherAttributesAndSubjectsAreIgnored(): void
    {
        $account = (new PerfectworldAccount())->setUser(self::createUser(1));

        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, self::vote(self::createUser(1), $account, 'ROLE_USER'));
        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, self::vote(self::createUser(1), new \stdClass()));
    }
}
