<?php

namespace App\HoyoverseBundle\MessageHandler;

use App\CoreBundle\Model\Notification\UniversalEmbed;
use App\CoreBundle\Service\Notification\UnifiedNotificationService;
use App\HoyoverseBundle\Entity\HoyoverseAccount;
use App\HoyoverseBundle\Message\UpdateCookieMessage;
use App\HoyoverseBundle\Repository\HoyoverseAccountRepository;
use App\HoyoverseBundle\Service\HoyolabCookieUtilsService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Message handler responsible for periodically refreshing expired HoYoLAB session tokens
 * for all registered accounts and dispatching failure/recovery notifications.
 */
#[AsMessageHandler]
class UpdateCookieMessageHandler
{
    public function __construct(
        private readonly HoyoverseAccountRepository $accountRepository,
        private readonly HoyolabCookieUtilsService  $cookieUtils,
        private readonly EntityManagerInterface     $entityManager,
        private readonly UnifiedNotificationService $notificationService,
    ) {
    }

    /**
     * Iterates over all configured accounts, executes token refresh routines,
     * and persists changes to the database.
     */
    public function __invoke(UpdateCookieMessage $message): void
    {
        $accounts = $this->accountRepository->findAll();

        foreach ($accounts as $account) {
            try {
                $this->processAccount($account);
            } catch (\Throwable $e) {
                $this->cookieUtils->getLogger()->error(
                    "Error updating cookie for account {$account->getId()}: {$e->getMessage()}"
                );
            }
        }

        // Persist updated cookies and status flags for all processed accounts
        $this->entityManager->flush();
    }

    /**
     * Processes token renewal for an individual HoYoverse account.
     * Evaluates whether notification dispatch is needed based on state transition.
     */
    public function processAccount(HoyoverseAccount $account): void
    {
        $rawCookie = $account->getCookie();
        if (empty($rawCookie)) {
            return;
        }

        $parsedCookie = $this->cookieUtils->parseCookie($rawCookie);

        // Skip renewal if no renewal secret (stoken) is configured
        if (!$parsedCookie->canAutoRenew()) {
            return;
        }

        $success = $this->cookieUtils->renew($parsedCookie);
        $previouslyFailed = $account->isCookieRefreshFailed();

        // Notification condition: notify only on state transitions (restored or newly failed)
        $shouldNotify = $success ? $previouslyFailed : !$previouslyFailed;

        if ($success) {
            $account->setCookie($parsedCookie->toStorageString());
            $account->setCookieRefreshFailed(false);
            $account->setLastCookieRefreshedAt(new \DateTimeImmutable());
        } else {
            $account->setCookieRefreshFailed(true);
        }

        if ($shouldNotify) {
            try {
                $this->notifyStatus($account, $success);
            } catch (\Throwable $e) {
                $this->cookieUtils->getLogger()->error(
                    "Could not notify account {$account->getId()}: {$e->getMessage()}"
                );
            }
        }
    }

    /**
     * Dispatches a notification to the account owner indicating recovery or failure.
     *
     * @param HoyoverseAccount $account   The target HoYoverse account entity.
     * @param bool             $recovered True if credentials were successfully restored, false on failure.
     */
    public function notifyStatus(HoyoverseAccount $account, bool $recovered): void
    {
        $accountId = $account->getId();
        $title = $recovered ? 'HoYoLAB Cookie Refresh Restored' : 'HoYoLAB Cookie Refresh Failed';
        $description = $recovered
            ? "Cookie refresh is working again for HoYoLAB account #{$accountId}."
            : "Could not refresh the cookie for HoYoLAB account #{$accountId}. Automatic code redemption may stop.";

        $notification = new UniversalEmbed()
            ->setTitle($title)
            ->setDescription($description)
            ->setColor($recovered ? 0x57F287 : 0xED4245)
            ->setFooterText('HoYoverse account')
            ->setTimestamp(new \DateTimeImmutable());

        $this->notificationService->sendToUser($account->getUser(), $notification);
    }
}