<?php

namespace App\GryphlineBundle\Service;

use App\CoreBundle\Model\GameNotes\GameNotesDailies;
use App\CoreBundle\Model\GameNotes\GameNotesProgressMetric;
use App\CoreBundle\Model\GameNotes\GameNotesWeeklies;
use App\GryphlineBundle\Model\Game\CheckInTrait;
use App\GryphlineBundle\Model\Game\GameInterface;
use App\GryphlineBundle\Model\Game\HasCheckInInterface;
use App\CoreBundle\Model\Game\HasDailiesInterface;
use App\CoreBundle\Model\Game\HasNotesInterface;
use App\CoreBundle\Model\Game\HasStaminaInterface;
use App\CoreBundle\Model\Game\HasWeekliesInterface;
use App\GryphlineBundle\Model\Game\NotesTrait;
use App\GryphlineBundle\Model\Notes\EndfieldNotes;
use App\CoreBundle\Model\GameNotes\GameNotesStamina;
use App\CoreBundle\Model\Game\RuntimeAccountDataInterface;
use App\GryphlineBundle\Model\RuntimeAccountData;
use App\GryphlineBundle\Model\SkportSessionCredentials;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * @implements HasNotesInterface<EndfieldNotes>
 */
#[AutoconfigureTag(name: 'gryphline.game.biz', attributes: ['key' => self::GAME_BIZ])]
#[AutoconfigureTag(name: 'gryphline.game.id', attributes: ['key' => self::GAME_ID])]
class EndfieldService implements GameInterface, HasCheckInInterface, HasNotesInterface, HasStaminaInterface, HasDailiesInterface, HasWeekliesInterface
{
    use CheckInTrait;
    use NotesTrait;

    public const int GAME_ID = 3;
    public const string GAME_BIZ = 'endfield';
    public const string GAME_NAME = 'Arknights: Endfield';

    public function __construct(
        #[Target('gryphline.skport.client')]
        private readonly HttpClientInterface $skportClient,
        private readonly SkportSignerService $signerService,
        private readonly SkportAuthService $authService,
        private readonly SkportUtilsService $skportUtils,
        private readonly DenormalizerInterface $denormalizer,
        #[Target('gryphline.endfield')]
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getGameId(): int
    {
        return self::GAME_ID;
    }

    public static function getGameBiz(): string
    {
        return self::GAME_BIZ;
    }

    public function getGameName(): string
    {
        return self::GAME_NAME;
    }

    public function getLogger(): LoggerInterface
    {
        return $this->logger;
    }

    public function getSkportUtils(): SkportUtilsService
    {
        return $this->skportUtils;
    }

    public function getSkportClient(): HttpClientInterface
    {
        return $this->skportClient;
    }

    public function getSignerService(): SkportSignerService
    {
        return $this->signerService;
    }

    public function getAuthService(): SkportAuthService
    {
        return $this->authService;
    }

    public function getDenormalizer(): DenormalizerInterface
    {
        return $this->denormalizer;
    }

    public function getUrlNotes(): string
    {
        return '/api/v1/game/endfield/card/detail';
    }

    public function getNotesClass(): string
    {
        return EndfieldNotes::class;
    }

    public function getRegenRate(): int
    {
        return 360;
    }

    public function getMaxStamina(): int
    {
        return 360;
    }

    public function getStaminaData(RuntimeAccountDataInterface $account): GameNotesStamina
    {
        /** @var EndfieldNotes $notes */
        $notes = $this->getNotes($account);

        return (new GameNotesStamina())
            ->setCurrentStamina($notes->getCurStamina())
            ->setMaxStamina($notes->getMaxStamina() ?? $this->getMaxStamina())
            ->setStaminaRecoverTime($notes->getStaminaRecoveryTime());
    }

    public function getDailiesData(RuntimeAccountDataInterface $account): GameNotesDailies
    {
        /** @var EndfieldNotes $notes */
        $notes = $this->getNotes($account);

        return (new GameNotesDailies())
            ->addDaily(
                (new GameNotesProgressMetric())
                    ->setName('Daily Activation')
                    ->setCurrentValue($notes->getDailyActivation())
                    ->setMaxValue($notes->getMaxDailyActivation() ?? 100)
            );
    }

    public function getWeekliesData(RuntimeAccountDataInterface $account): GameNotesWeeklies
    {
        /** @var EndfieldNotes $notes */
        $notes = $this->getNotes($account);

        return (new GameNotesWeeklies())
            ->addWeekly(
                (new GameNotesProgressMetric())
                    ->setName('Weekly Mission')
                    ->setCurrentValue($notes->getWeeklyScore())
                    ->setMaxValue($notes->getWeeklyTotal() ?? 10)
            );
    }

    public function ensureCredentials(RuntimeAccountDataInterface $account, bool $force = false): SkportSessionCredentials
    {
        $creds = $account->getCredentials();
        if ($force || $creds === null || $creds->isExpired()) {
            $creds = $this->authService->getOrRefreshCredentials($account->getAccount(), force: $force);
            $account->setCredentials($creds);
        }

        return $creds;
    }
}
