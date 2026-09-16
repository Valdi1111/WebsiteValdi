<?php

namespace App\HoyoverseBundle\MessageHandler;

use App\HoyoverseBundle\Entity\HoyoverseDiaryEntry;
use App\HoyoverseBundle\Message\SyncDiaryPageMessage;
use App\HoyoverseBundle\Message\TaskMessageInterface;
use App\HoyoverseBundle\Model\Game\GameInterface;
use App\HoyoverseBundle\Model\Game\HasDiaryInterface;
use App\HoyoverseBundle\Model\RuntimeAccountData;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * @extends AbstractTaskMessageHandler<SyncDiaryPageMessage>
 */
#[AsMessageHandler]
class SyncDiaryPageMessageHandler extends AbstractTaskMessageHandler
{
    /**
     * Maximum number of records requested per API call.
     */
    private const int PAGE_SIZE = 100;

    /**
     * Dynamic jitter delay boundaries (in milliseconds) between consecutive page requests.
     */
    private const int DELAY_MIN_MS = 1500;
    private const int DELAY_MAX_MS = 2500;

    private EntityManagerInterface $entityManager;
    private MessageBusInterface $bus;

    #[Required]
    public function setEntityManager(EntityManagerInterface $entityManager): void
    {
        $this->entityManager = $entityManager;
    }

    #[Required]
    public function setBus(MessageBusInterface $bus): void
    {
        $this->bus = $bus;
    }

    public function __invoke(SyncDiaryPageMessage $message): void
    {
        $this->handleTask($message);
    }

    protected function processProfile(
        GameInterface        $gameService,
        RuntimeAccountData   $accountData,
        TaskMessageInterface $message
    ): void
    {
        if (!$gameService instanceof HasDiaryInterface) {
            return;
        }

        // 1. Purge stale entries on the first page to ensure monthly idempotency
        if ($message->getPage() === 1) {
            $this->entityManager->createQueryBuilder()
                ->delete(HoyoverseDiaryEntry::class, 'e')
                ->where('e.gameProfile = :profile')
                ->andWhere('e.currency = :currency')
                ->andWhere('e.period = :period')
                ->setParameter('profile', $accountData->getGameProfile())
                ->setParameter('currency', $message->getCurrency())
                ->setParameter('period', $message->getPeriod())
                ->getQuery()
                ->execute();
        }

        // 2. Fetch diary entries for the current page from the upstream API
        $diaryEntries = $gameService->getDiaryEntries(
            $accountData,
            $message->getCurrency(),
            $message->getMonth(),
            $message->getPeriod(),
            $message->getPage(),
            self::PAGE_SIZE
        );

        // 3. Attach and persist all fetched entries to the profile
        foreach ($diaryEntries as $diaryEntry) {
            $accountData->getGameProfile()->addDiaryEntry($diaryEntry);
        }

        $this->entityManager->flush();

        // 4. Continue pagination if the current response reached full capacity
        if (count($diaryEntries) >= self::PAGE_SIZE) {
            $nextPageDelayMs = random_int(self::DELAY_MIN_MS, self::DELAY_MAX_MS);

            $this->bus->dispatch(
                new SyncDiaryPageMessage(
                    $accountData->getGameProfile()->getId(),
                    $message->getCurrency(),
                    $message->getMonth(),
                    $message->getPeriod(),
                    $message->getPage() + 1
                ),
                [new DelayStamp($nextPageDelayMs)]
            );
        }
    }

}