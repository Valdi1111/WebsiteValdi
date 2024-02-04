<?php

namespace App\CoreBundle\Security;

use App\CoreBundle\Entity\Token;
use App\CoreBundle\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

class AccessTokenHandler implements AccessTokenHandlerInterface
{

    public function __construct(private readonly EntityManagerInterface $commonEntityManager)
    {
    }


    public function getUserBadgeFrom(#[\SensitiveParameter] string $accessToken): UserBadge
    {
        $token = $this->commonEntityManager->getRepository(Token::class)->findOneBy(['access_token' => $accessToken]);
        if (!$token || !$token->isValid()) {
            throw new BadCredentialsException('Invalid credentials.');
        }
        $user = $this->commonEntityManager->getRepository(User::class)->findOneBy(['id' => $token->getId()]);
        return new UserBadge($user->getUserIdentifier());
    }

}