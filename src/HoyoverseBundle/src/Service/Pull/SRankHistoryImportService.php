<?php

namespace App\HoyoverseBundle\Service\Pull;

use App\GamesBundle\Exception\PullProfileLockedException;
use App\GamesBundle\Model\Game\HasManualPullsInterface;
use App\GamesBundle\Model\Game\HasSRankHistoryImportInterface;
use App\GamesBundle\Model\Game\RarityConfig;
use App\GamesBundle\Model\Pull\Manual\ManualPull;
use App\GamesBundle\Model\Pull\PullImportProgress;
use App\GamesBundle\Model\Pull\PullImportSummary;
use App\GamesBundle\Model\Pull\PullOrigin;
use App\GamesBundle\Model\Pull\SRankHistory\SRankHistoryBanner;
use App\GamesBundle\Model\Pull\SRankHistory\SRankHistoryRecord;
use App\GamesBundle\Model\Pull\SRankHistory\SRankHistoryRowUpdate;
use App\GamesBundle\Service\Pull\SRankHistoryReconciler;
use App\HoyoverseBundle\Entity\HoyoverseGameProfile;
use App\HoyoverseBundle\Entity\HoyoversePull;
use App\HoyoverseBundle\Model\Game\GameInterface;
use App\HoyoverseBundle\Model\Game\HasPullTrackerInterface;
use App\HoyoverseBundle\Model\ParsedCookie;
use App\HoyoverseBundle\Model\RuntimeAccountData;
use App\HoyoverseBundle\Repository\HoyoversePullRepository;
use App\HoyoverseBundle\Service\HoyolabCookieUtilsService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Service\ServiceCollectionInterface;

/**
 * Imports the official S-rank history of a profile (HasSRankHistoryImportInterface, e.g. the Zenless "Signal Search"
 * statistics) into its missing pulls: official S rows for the S the stored history lacks, the official pity and rate-up
 * flag on the stored S, then the filler rows (ManualPullService::realign()). Additive only: a stored S the history does
 * not list is never deleted (it is a conflict, or newer than the history); only the realign step creates, moves and
 * deletes filler rows.
 *
 * Banner by banner, under the profile lock:
 * 1. SRankHistoryReconciler checks the history, aligns it with the stored S and simulates the result; a banner it
 *    refuses (conflict) is skipped and nothing is written there.
 * 2. Nothing to change (e.g. a second import): nothing is written, not even a transaction.
 * 3. Otherwise, in one transaction: the stored S updated, the official S created, realign, then every record must be at
 *    its pull number in the stored order. If one is not, the banner is rolled back.
 *
 * A dry run stops after step 2 and reports what would be written. Banner totals that differ from the official ones are
 * warnings only (pulls made since the last sync). Every value entered by the user that the official history replaces is
 * reported first in the warnings, prefixed with the banner name and never cut by the MAX_WARNINGS limit of the others.
 */
class SRankHistoryImportService
{
    /** Importer key of the summaries (PullImportSummary::$importerKey) */
    public const string IMPORTER_KEY = 's_rank_history';

    public const string IMPORTER_LABEL = 'Official S-Rank History';

    private const float LOCK_TTL_SECONDS = 300.0;
    private const int MAX_WARNINGS = 50;

    /** Length of the item_id column */
    private const int ITEM_ID_MAX_LENGTH = 32;

    /** Lengths of the item_type and name columns */
    private const int ITEM_TYPE_MAX_LENGTH = 32;
    private const int NAME_MAX_LENGTH = 128;

    /**
     * @param ServiceCollectionInterface<GameInterface> $locatorByGameId
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly HoyoversePullRepository $pullRepository,
        private readonly HoyoversePullStatsCalculator $statsCalculator,
        private readonly SRankHistoryReconciler $reconciler,
        private readonly ManualPullService $manualPullService,
        private readonly HoyolabCookieUtilsService $cookieUtils,
        private readonly LockFactory $lockFactory,
        #[AutowireLocator(services: 'hoyoverse.game.id', indexAttribute: 'key')]
        private readonly ServiceCollectionInterface $locatorByGameId,
        #[Target('hoyoverse')]
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * The game of the profile, when it can import an S-rank history (null otherwise).
     *
     * @return (GameInterface&HasPullTrackerInterface&HasSRankHistoryImportInterface)|null
     */
    public function getSupportedGame(HoyoverseGameProfile $profile): ?GameInterface
    {
        $gameId = (int) $profile->getGameId();
        if (!$this->locatorByGameId->has($gameId)) {
            return null;
        }

        $gameService = $this->locatorByGameId->get($gameId);

        return $gameService instanceof HasPullTrackerInterface && $gameService instanceof HasManualPullsInterface && $gameService instanceof HasSRankHistoryImportInterface
            ? $gameService
            : null;
    }

    /**
     * Whether the HoYoLAB cookie of the account can be parsed (ltoken_v2, ltuid_v2, ltmid_v2): the history is read with it.
     */
    public function hasUsableCookie(HoyoverseGameProfile $profile): bool
    {
        return $this->parseCookie($profile) !== null;
    }

    /**
     * @param (callable(PullImportProgress): void)|null $onProgress
     *
     * @throws \InvalidArgumentException   when the game cannot import an S-rank history or the account has no usable cookie
     * @throws PullProfileLockedException  when another pull operation holds the profile lock
     * @throws \RuntimeException           when the history cannot be read (nothing is written then)
     */
    public function import(HoyoverseGameProfile $profile, ?callable $onProgress = null, bool $dryRun = false, bool $blockingLock = true): PullImportSummary
    {
        $gameService = $this->getSupportedGame($profile)
            ?? throw new \InvalidArgumentException('This game profile cannot import an official S-rank history.');
        $parsedCookie = $this->parseCookie($profile)
            ?? throw new \InvalidArgumentException('The HoYoLAB cookie of the account (ltoken_v2, ltuid_v2, ltmid_v2) is missing or incomplete: log in again.');

        $profileId = (int) $profile->getId();
        $lock = $this->lockFactory->createLock(PullBackupImportService::getProfileLockKey($profileId), self::LOCK_TTL_SECONDS);
        if (!$lock->acquire($blockingLock)) {
            throw new PullProfileLockedException(\sprintf('Another pull operation is already running for profile #%d.', $profileId));
        }

        try {
            $bannerGroups = $gameService->getGachaConfig()->getBannerGroups();
            $banners = [];
            foreach ($bannerGroups as $bannerKey => $group) {
                $banners[(string) $bannerKey] = ['gacha_type' => (string) $bannerKey, 'name' => $group['name'], 'status' => 'queued', 'imported' => 0, 'updated' => 0, 'matched' => 0, 'error' => null];
            }
            // Replaced user values: always listed in full and first (the dry run is the preview the user relies on)
            $overwrites = [];
            $warnings = [];
            $droppedWarnings = 0;
            $addWarning = static function (string $warning) use (&$warnings, &$droppedWarnings): void {
                if (\count($warnings) < self::MAX_WARNINGS) {
                    $warnings[] = $warning;
                } else {
                    ++$droppedWarnings;
                }
            };
            $processed = 0;
            $created = 0;
            $updated = 0;
            $skipped = 0;
            $report = static function (string $phase) use ($onProgress, &$banners, &$overwrites, &$warnings, &$processed, &$created, &$updated, &$skipped): void {
                if ($onProgress !== null) {
                    $onProgress(new PullImportProgress($phase, \count($banners), $processed, $created, $skipped, [...$overwrites, ...$warnings], $updated, $banners));
                }
            };

            $report('fetching');
            $history = $gameService->getSRankHistory((new RuntimeAccountData())->setParsedCookie($parsedCookie)->setGameProfile($profile));
            $lock->refresh();
            foreach ($history->warnings as $warning) {
                $addWarning($warning);
            }
            foreach ($history->banners as $historyBanner) {
                if (!isset($banners[$historyBanner->bannerKey]) && $historyBanner->records !== []) {
                    $addWarning(\sprintf('Banner "%s" of the official history is not configured: ignored.', $historyBanner->bannerKey));
                }
            }

            $rarityConfig = $gameService->getRarityConfig();
            foreach ($bannerGroups as $bannerKey => $group) {
                $bannerKey = (string) $bannerKey;
                $banners[$bannerKey]['status'] = 'running';
                $report('importing');

                $historyBanner = $history->getBanner($bannerKey);
                $result = $historyBanner === null || $historyBanner->records === []
                    ? ['status' => 'completed', 'created' => 0, 'updated' => 0, 'matched' => 0, 'error' => null, 'warnings' => [], 'overwrites' => []]
                    : $this->importBanner($profileId, $gameService, $rarityConfig, $bannerKey, $group, $historyBanner, $dryRun);

                foreach ($result['overwrites'] as $overwrite) {
                    $overwrites[] = \sprintf('%s: %s', $group['name'], $overwrite);
                }
                foreach ($result['warnings'] as $warning) {
                    $addWarning(\sprintf('%s: %s', $group['name'], $warning));
                }
                if ($result['status'] !== 'completed') {
                    ++$skipped;
                    $this->logger?->warning(\sprintf('S-rank history import of banner %s for profile #%d %s: %s', $bannerKey, $profileId, $result['status'], (string) $result['error']));
                }
                $created += $result['created'];
                $updated += $result['updated'];
                $banners[$bannerKey] = [
                    ...$banners[$bannerKey],
                    'status' => $result['status'],
                    'imported' => $result['created'],
                    'updated' => $result['updated'],
                    'matched' => $result['matched'],
                    'error' => $result['error'],
                ];
                ++$processed;
                $lock->refresh();
                $report('importing');
            }

            if ($droppedWarnings > 0) {
                $warnings[] = \sprintf('%d more warning(s) not shown.', $droppedWarnings);
            }
            $this->logger?->info(\sprintf(
                'S-rank history import finished for profile #%d: created=%d, updated=%d, skipped banners=%d, dryRun=%s.',
                $profileId,
                $created,
                $updated,
                $skipped,
                $dryRun ? 'true' : 'false',
            ));

            return new PullImportSummary(
                importerKey: self::IMPORTER_KEY,
                importerLabel: self::IMPORTER_LABEL,
                total: \count($banners),
                imported: $created,
                skipped: $skipped,
                warnings: [...$overwrites, ...$warnings],
                dryRun: $dryRun,
                updated: $updated,
                banners: $banners,
            );
        } finally {
            $lock->release();
        }
    }

    /**
     * @param array{key: string, name: string, gacha_types: list<string>, s_max_pity: int} $group
     *
     * @return array{status: string, created: int, updated: int, matched: int, error: ?string, warnings: list<string>, overwrites: list<string>}
     *               "matched": stored S found in the history (changed or not)
     */
    private function importBanner(
        int $profileId,
        GameInterface&HasPullTrackerInterface $gameService,
        RarityConfig $rarityConfig,
        string $bannerKey,
        array $group,
        SRankHistoryBanner $historyBanner,
        bool $dryRun,
    ): array {
        // A rolled-back banner clears the entity manager: always work on a managed reference
        /** @var HoyoverseGameProfile $profile */
        $profile = $this->entityManager->getReference(HoyoverseGameProfile::class, $profileId);

        $entries = $this->statsCalculator->toTimelineEntries($this->pullRepository->findBannerRows($profile, $group['gacha_types']), $rarityConfig);
        $plan = $this->reconciler->reconcile($historyBanner, $entries, $group['s_max_pity']);
        if ($plan->isRefused()) {
            return [
                'status' => 'skipped',
                'created' => 0,
                'updated' => 0,
                'matched' => 0,
                'error' => self::firstOf($plan->conflicts),
                'warnings' => [...$plan->warnings, ...array_map(static fn (string $conflict): string => 'not imported: '.$conflict, $plan->conflicts)],
                'overwrites' => [],
            ];
        }

        $rows = [];
        $ids = array_map(static fn (SRankHistoryRowUpdate $update): int => $update->id, $plan->updates);
        if ($ids !== []) {
            foreach ($this->pullRepository->findBy(['gameProfile' => $profile, 'id' => $ids]) as $pull) {
                $rows[(int) $pull->getId()] = $pull;
            }
        }
        $changes = array_values(array_filter($plan->updates, fn (SRankHistoryRowUpdate $update): bool => isset($rows[$update->id]) && $this->hasChanges($rows[$update->id], $update)));
        $result = [
            'status' => 'completed',
            'created' => \count($plan->creates),
            'updated' => \count($changes),
            'matched' => \count($plan->updates),
            'error' => null,
            'warnings' => $plan->warnings,
            'overwrites' => $plan->overwrites,
        ];
        // Fillers out of line with the stored S (e.g. a sync that stopped before its last page): realign and check anyway
        if ($plan->creates === [] && $changes === [] && $plan->realignNeeded) {
            $result['warnings'][] = 'The unknown pulls are out of line with the stored S: the import realigns them.';
        }
        if ($dryRun || ($plan->creates === [] && $changes === [] && !$plan->realignNeeded)) {
            return $result;
        }

        $this->entityManager->beginTransaction();
        try {
            foreach ($changes as $update) {
                $this->applyUpdate($rows[$update->id], $update);
            }
            foreach ($plan->creates as $record) {
                $this->entityManager->persist($this->createOfficialS($profile, $group, $rarityConfig, $record));
            }
            $this->entityManager->flush();

            $this->manualPullService->realign($profile, $gameService, $bannerKey);
            $this->entityManager->flush();

            // The stored order decides: every record must be at its pull number, otherwise nothing of this banner stays
            $mismatches = $this->reconciler->findPositionMismatches(
                $plan->records,
                $this->statsCalculator->toTimelineEntries($this->pullRepository->findBannerRows($profile, $group['gacha_types']), $rarityConfig),
            );
            if ($mismatches === []) {
                $this->entityManager->commit();

                return $result;
            }
        } catch (\Throwable $e) {
            $this->rollBack();

            throw $e;
        }

        $this->rollBack();

        return [
            'status' => 'failed',
            'created' => 0,
            'updated' => 0,
            'matched' => 0,
            'error' => 'rolled back: '.self::firstOf($mismatches),
            'warnings' => [...$plan->warnings, ...array_map(static fn (string $mismatch): string => 'rolled back: '.$mismatch, $mismatches)],
            'overwrites' => [],
        ];
    }

    /**
     * Whether the update changes the stored row. A record without a rate-up flag keeps the stored one.
     */
    private function hasChanges(HoyoversePull $pull, SRankHistoryRowUpdate $update): bool
    {
        $record = $update->record;
        if ($pull->getDeclaredPity() !== $update->declaredPity || $pull->isUp() !== ($record->isUp ?? $pull->isUp())) {
            return true;
        }
        if (!$update->official) {
            return false;
        }

        return $pull->getOrigin() !== PullOrigin::Official
            || $pull->getPullNumber() !== $record->pullNumber
            || $pull->getPulledAt()?->format(ManualPull::DATE_FORMAT) !== $record->pulledAt->format(ManualPull::DATE_FORMAT)
            || $pull->getItemId() !== self::itemIdOf($record)
            || $pull->getName() !== mb_substr($record->name, 0, self::NAME_MAX_LENGTH)
            || $pull->getItemType() !== mb_substr($record->itemType, 0, self::ITEM_TYPE_MAX_LENGTH);
    }

    /**
     * A recorded S keeps its date, item and origin: it only takes the declared pity and the rate-up flag. A manual or
     * official S becomes the official S of the record.
     */
    private function applyUpdate(HoyoversePull $pull, SRankHistoryRowUpdate $update): void
    {
        $record = $update->record;
        $pull->setDeclaredPity($update->declaredPity)
            ->setIsUp($record->isUp ?? $pull->isUp());
        if (!$update->official) {
            return;
        }

        if ($pull->getOrigin() !== PullOrigin::Official) {
            $pull->setRawPayload(['source' => PullOrigin::Official->value]);
        }
        $pull->setOrigin(PullOrigin::Official)
            ->setPullNumber($record->pullNumber)
            ->setPulledAt($record->pulledAt)
            ->setItemId(self::itemIdOf($record))
            ->setName(mb_substr($record->name, 0, self::NAME_MAX_LENGTH))
            ->setItemType(mb_substr($record->itemType, 0, self::ITEM_TYPE_MAX_LENGTH));
    }

    /**
     * @param array{name: string, gacha_types: list<string>} $group
     */
    private function createOfficialS(HoyoverseGameProfile $profile, array $group, RarityConfig $rarityConfig, SRankHistoryRecord $record): HoyoversePull
    {
        return (new HoyoversePull())
            ->setGameProfile($profile)
            ->setPullId(ManualPull::OFFICIAL_ID_PREFIX.Uuid::v7()->toRfc4122())
            ->setGachaType((string) $group['gacha_types'][0])
            ->setGachaName($group['name'])
            ->setItemType(mb_substr($record->itemType, 0, self::ITEM_TYPE_MAX_LENGTH))
            ->setItemId(self::itemIdOf($record))
            ->setName(mb_substr($record->name, 0, self::NAME_MAX_LENGTH))
            ->setRarity($rarityConfig->getS()->getRarity())
            ->setPulledAt($record->pulledAt)
            ->setOrigin(PullOrigin::Official)
            ->setDeclaredPity($record->pity)
            ->setPullNumber($record->pullNumber)
            ->setIsUp($record->isUp)
            ->setRawPayload(['source' => PullOrigin::Official->value]);
    }

    /**
     * tryParseCookie(): the exception of parseCookie() contains the cookie, which must never reach the tracker.
     */
    private function parseCookie(HoyoverseGameProfile $profile): ?ParsedCookie
    {
        return $this->cookieUtils->tryParseCookie($profile->getAccount()?->getCookie());
    }

    private static function itemIdOf(SRankHistoryRecord $record): ?string
    {
        return $record->itemId !== null && mb_strlen($record->itemId) <= self::ITEM_ID_MAX_LENGTH ? $record->itemId : null;
    }

    /**
     * @param non-empty-list<string> $messages
     */
    private static function firstOf(array $messages): string
    {
        return \count($messages) > 1 ? \sprintf('%s (and %d more)', $messages[0], \count($messages) - 1) : $messages[0];
    }

    private function rollBack(): void
    {
        $this->entityManager->rollback();
        // The rolled-back rows are still in the identity map with their in-memory values
        $this->entityManager->clear();
    }
}
