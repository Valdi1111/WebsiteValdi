<?php

namespace App\HoyoverseBundle\MessageHandler\Profile;

use App\CoreBundle\Message\ProfileTaskMessageInterface;
use App\HoyoverseBundle\Message\Profile\SyncPullsPageProfileMessage;
use App\HoyoverseBundle\Model\Game\GameInterface;
use App\HoyoverseBundle\Model\Game\HasPullTrackerInterface;
use App\HoyoverseBundle\Model\Game\HasStokenPullImportInterface;
use App\HoyoverseBundle\Model\RuntimeAccountData;
use App\HoyoverseBundle\Repository\HoyoversePullRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * @implements AbstractProfileTaskMessageHandler<SyncPullsPageProfileMessage>
 */
#[AsMessageHandler]
class SyncPullsPageProfileHandler extends AbstractProfileTaskMessageHandler
{
    /**
     * Maximum number of records requested per gacha API call.
     */
    private const int PAGE_SIZE = 20;

    /**
     * Dynamic jitter delay boundaries (in milliseconds) between consecutive page requests.
     */
    private const int DELAY_MIN_MS = 1500;
    private const int DELAY_MAX_MS = 2500;

    private EntityManagerInterface $entityManager;
    private HoyoversePullRepository $pullRepository;
    private MessageBusInterface $bus;

    #[Required]
    public function setEntityManager(EntityManagerInterface $entityManager): void
    {
        $this->entityManager = $entityManager;
    }

    #[Required]
    public function setPullRepository(HoyoversePullRepository $pullRepository): void
    {
        $this->pullRepository = $pullRepository;
    }

    #[Required]
    public function setBus(MessageBusInterface $bus): void
    {
        $this->bus = $bus;
    }

    public function __invoke(SyncPullsPageProfileMessage $message): void
    {
        $this->handleProfile($message);
    }

    protected function processProfile(
        GameInterface $gameService,
        RuntimeAccountData $accountData,
        ProfileTaskMessageInterface $message
    ): void {
        if (!$gameService instanceof HasPullTrackerInterface) {
            return;
        }

        if (!$message instanceof SyncPullsPageProfileMessage) {
            return;
        }

        $profile = $accountData->getGameProfile();
        $authkey = $message->getAuthkey();
        $extraQueryParams = $message->getExtraQueryParams();

        // 1. Resolve or generate authkey on the first page if not already carried in the message
        if (($authkey === null || $authkey === '') && $gameService instanceof HasStokenPullImportInterface) {
            $authkey = $this->cookieUtils->generateAuthKey($profile, $gameService->getAuthkeyAppId());
        }

        if ($authkey === null || $authkey === '') {
            $this->logger->warning(sprintf(
                'Cannot sync pulls page for %s (Profile %d, Banner %s): no authkey available and game does not support SToken import.',
                $profile->getNickname(),
                $profile->getId(),
                $message->getGachaType()
            ));
            return;
        }

        // 2. Fetch gacha pulls for the current page from the upstream API
        $pulls = $gameService->fetchPullsPage(
            $profile,
            $message->getGachaType(),
            $authkey,
            $message->getEndId(),
            $extraQueryParams,
            self::PAGE_SIZE
        );

        if (empty($pulls)) {
            return;
        }

        // 3. Persist new pulls and check if we encountered an already stored record (idempotent stop)
        $pageHasExistingPull = false;
        $newCount = 0;

        foreach ($pulls as $pull) {
            if ($this->pullRepository->existsByProfileAndPullId($profile, (string) $pull->getPullId())) {
                $pageHasExistingPull = true;
                break;
            }

            $this->entityManager->persist($pull);
            $newCount++;
        }

        $this->entityManager->flush();

        if ($newCount > 0) {
            $this->logger->info(sprintf(
                'Recorded %d new pull(s) for %s (Banner %s, end_id %s).',
                $newCount,
                $profile->getNickname(),
                $message->getGachaType(),
                $message->getEndId()
            ));
        }

        // 4. Continue pagination if no existing record was hit and the current page reached full capacity
        if (!$pageHasExistingPull && count($pulls) >= self::PAGE_SIZE) {
            $lastPull = end($pulls);
            $nextEndId = (string) $lastPull->getPullId();
            $nextPageDelayMs = random_int(self::DELAY_MIN_MS, self::DELAY_MAX_MS);

            $this->bus->dispatch(
                new SyncPullsPageProfileMessage(
                    $profile->getId(),
                    $message->getGachaType(),
                    $nextEndId,
                    $authkey,
                    $extraQueryParams
                ),
                [new DelayStamp($nextPageDelayMs)]
            );
        }
    }
}
