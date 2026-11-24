<?php

namespace App\CoreBundle\Service\Discord;

use App\CoreBundle\Entity\User;
use App\CoreBundle\Exception\DiscordAccountAlreadyLinkedException;
use App\CoreBundle\Model\Discord\DiscordUser;
use App\CoreBundle\Repository\UserRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Links and unlinks the Discord account of a user (User::discordUserId, read by the notifications and the bot).
 */
class DiscordAccountLinker
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Links the account, or refreshes its names and avatar when the user links the same one again.
     *
     * @throws DiscordAccountAlreadyLinkedException when another user has the same Discord id; nothing is saved then
     */
    public function link(User $user, DiscordUser $account): void
    {
        $owner = $this->userRepository->findOneBy(['discordUserId' => $account->id]);
        if ($owner !== null && $owner->getId() !== $user->getId()) {
            throw new DiscordAccountAlreadyLinkedException();
        }

        $user->linkDiscordAccount($account);
        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException $e) {
            // Linked to another user between the check and the flush: the unique index decides
            throw new DiscordAccountAlreadyLinkedException($e);
        }
    }

    public function unlink(User $user): void
    {
        $user->unlinkDiscordAccount();
        $this->entityManager->flush();
    }
}
