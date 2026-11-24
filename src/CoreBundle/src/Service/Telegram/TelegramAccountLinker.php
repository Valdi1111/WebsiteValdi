<?php

namespace App\CoreBundle\Service\Telegram;

use App\CoreBundle\Entity\User;
use App\CoreBundle\Exception\TelegramAccountAlreadyLinkedException;
use App\CoreBundle\Exception\TelegramBotException;
use App\CoreBundle\Exception\TelegramMessagesNotAllowedException;
use App\CoreBundle\Model\Telegram\TelegramUser;
use App\CoreBundle\Repository\UserRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Links and unlinks the Telegram account of a user (User::telegramChatId, read by the notifications).
 */
class TelegramAccountLinker
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TelegramBot $bot,
        #[Autowire('%domain_name%')]
        private readonly string $domainName,
    ) {
    }

    /**
     * Links the account, or refreshes its names when the user links the same one again.
     *
     * Before saving, the bot sends a welcome message: it is the only way to know that the user let the bot message
     * them at login, since the token does not tell. An account the bot cannot reach would never get the notifications.
     * In the rare case of another user linking the same account between the check and the flush, the welcome message
     * is sent but the account is not linked.
     *
     * @throws TelegramAccountAlreadyLinkedException when another user has the same Telegram id; nothing is sent nor saved then
     * @throws TelegramMessagesNotAllowedException   when the user does not let the bot message them; nothing is saved
     * @throws TelegramBotException                  when the welcome message could not be sent; nothing is saved
     */
    public function link(User $user, TelegramUser $account): void
    {
        $owner = $this->userRepository->findOneBy(['telegramChatId' => $account->id]);
        if ($owner !== null && $owner->getId() !== $user->getId()) {
            throw new TelegramAccountAlreadyLinkedException();
        }

        // The private chat with the bot has the id of the user
        $this->bot->sendMessage($account->id, sprintf('✅ Your Telegram account is now linked to %s: you will receive the notifications here.', $this->domainName));

        $user->linkTelegramAccount($account);
        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException $e) {
            // Linked to another user between the check and the flush: the unique index decides
            throw new TelegramAccountAlreadyLinkedException($e);
        }
    }

    public function unlink(User $user): void
    {
        $user->unlinkTelegramAccount();
        $this->entityManager->flush();
    }
}
