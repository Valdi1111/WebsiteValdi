<?php

namespace App\HoyoverseBundle\Service\Pull;

use App\GamesBundle\Model\Pull\PullOperationKind;
use App\HoyoverseBundle\Entity\HoyoverseGameProfile;
use App\HoyoverseBundle\Message\Profile\ImportSRankHistoryMessage;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Queues an official S-rank history import as a tracked operation, after the checks shared by the API endpoint and the
 * console command (--async): the game can read the history, the HoYoLAB cookie can be parsed, and no S-rank history
 * import of the profile is already queued or running.
 */
class SRankHistoryImportLauncher
{
    public function __construct(
        private readonly SRankHistoryImportService $importService,
        private readonly PullOperationTracker $operationTracker,
        private readonly MessageBusInterface $bus,
    ) {
    }

    /**
     * @return array<string, mixed> The tracked operation
     *
     * @throws BadRequestHttpException when the game cannot import the history or the HoYoLAB cookie cannot be parsed
     * @throws ConflictHttpException   when an S-rank history import of the profile is already queued or running
     */
    public function queue(HoyoverseGameProfile $profile, ?int $userId): array
    {
        $gameService = $this->importService->getSupportedGame($profile)
            ?? throw new BadRequestHttpException('This game profile cannot import an official S-rank history.');

        if (!$this->importService->hasUsableCookie($profile)) {
            throw new BadRequestHttpException('The HoYoLAB cookie of this account is missing or incomplete: log in again.');
        }

        $profileId = (int) $profile->getId();
        if ($this->operationTracker->hasActiveOperation($profileId, PullOperationKind::S_RANK_HISTORY_IMPORT)) {
            throw new ConflictHttpException('An official S-rank history import is already in progress for this profile.');
        }

        $operation = $this->operationTracker->startSRankHistoryImport($profileId, $userId, $gameService->getGachaConfig()->getBannerTypes());
        $this->bus->dispatch(new ImportSRankHistoryMessage((string) $operation['id'], $profileId));

        return $operation;
    }
}
