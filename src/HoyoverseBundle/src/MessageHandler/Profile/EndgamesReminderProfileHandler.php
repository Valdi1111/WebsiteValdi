<?php

namespace App\HoyoverseBundle\MessageHandler\Profile;

use App\CoreBundle\Model\Notification\EmbedField;
use App\CoreBundle\Model\Notification\UniversalEmbed;
use App\GamesBundle\Message\ProfileTaskMessageInterface;
use App\GamesBundle\Model\Endgame\EndgameDefinition;
use App\GamesBundle\Model\Endgame\EndgameReminder;
use App\GamesBundle\Model\Endgame\EndgameSettings;
use App\GamesBundle\Model\Endgame\GameEndgame;
use App\GamesBundle\Model\Game\HasEndgamesInterface;
use App\HoyoverseBundle\Exception\HoyolabException;
use App\HoyoverseBundle\Message\Profile\EndgamesReminderProfileMessage;
use App\HoyoverseBundle\Model\Game\GameInterface;
use App\HoyoverseBundle\Model\RuntimeAccountData;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * Reminds the endgame modes whose period ends within their configured window
 * (hoyoverse.endgames_reminder.<game>.<mode>.days_before_end) and that still need something according to the
 * profile's endgamesSettings (not played yet, or not at the maximum). One notification per profile, one row per mode.
 *
 * @implements AbstractProfileTaskMessageHandler<EndgamesReminderProfileMessage>
 */
#[AsMessageHandler]
class EndgamesReminderProfileHandler extends AbstractProfileTaskMessageHandler
{
    /** Empty field completing a notification row on Discord (Telegram skips it) */
    public const string FILLER = "\u{200b}";

    private ?ClockInterface $clock = null;

    /**
     * @var array<string, array<string, array{days_before_end: int}>>
     */
    private array $endgamesConfig = [];

    #[Required]
    public function setClock(ClockInterface $clock): void
    {
        $this->clock = $clock;
    }

    /**
     * @param array<string, array<string, array{days_before_end: int}>> $endgamesConfig game key => mode key => window
     */
    #[Required]
    public function setEndgamesConfig(
        #[Autowire(param: 'hoyoverse.endgames_reminder')]
        array $endgamesConfig
    ): void
    {
        $this->endgamesConfig = $endgamesConfig;
    }

    /**
     * Reminder window of a mode, in days of 24 hours.
     */
    public function getDaysBeforeEnd(string $gameKey, EndgameDefinition $definition): int
    {
        return $this->endgamesConfig[$gameKey][$definition->key]['days_before_end'] ?? $definition->defaultDaysBeforeEnd;
    }

    public function __invoke(EndgamesReminderProfileMessage $message): void
    {
        $this->handleProfile($message);
    }

    /**
     * Every mode is read on its own: one that cannot be read is left out with a warning and the others are still
     * reminded. Only when every mode requested fails, the first error is thrown, so an account-wide problem (expired
     * cookie, private game record) fails the task like in the other reminders. Modes turned off are not requested.
     */
    protected function processProfile(
        GameInterface $gameService,
        RuntimeAccountData $accountData,
        ProfileTaskMessageInterface $message
    ): void
    {
        if (!$gameService instanceof HasEndgamesInterface) {
            return;
        }

        $now = $this->getNow();
        $definitions = $gameService::getEndgameDefinitions();
        $settings = EndgameSettings::resolve($definitions, $accountData->getGameProfile()->getEndgamesSettings());

        $rows = [];
        $errors = [];
        $requested = 0;
        foreach ($definitions as $definition) {
            $modeSettings = $settings[$definition->key];
            if (array_filter($modeSettings, static fn (EndgameReminder $reminder) => $reminder !== EndgameReminder::Off) === []) {
                continue;
            }

            $requested++;
            try {
                $endgames = $gameService->fetchEndgame($definition, $accountData);
            } catch (\Throwable $e) {
                $errors[] = $e;
                $this->logUnreadableMode($definition, $accountData, $e);
                continue;
            }

            $row = $this->createRow($gameService, $definition, $endgames, $modeSettings, $accountData, $now);
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        if ($errors !== [] && count($errors) === $requested) {
            throw $errors[0];
        }

        if ($rows === []) {
            return;
        }

        $notification = $this->createNotificationWithAccountData($accountData, "Endgames Reminder")
            ->setDescription("Don't Forget to Do Your Endgames!");
        foreach ($rows as $row) {
            foreach ($row as $field) {
                $notification->addEmbedField($field);
            }
        }

        $this->sendNotificationToAccountData($accountData, $notification);
    }

    /**
     * One row of the notification: each variant of the mode (or an empty field when it is off or locked), then
     * "Ends in". Null when no variant needs a reminder within the window.
     *
     * @param list<GameEndgame>              $endgames
     * @param array<string, EndgameReminder> $modeSettings variant key => reminder
     * @return list<EmbedField>|null
     */
    private function createRow(
        GameInterface&HasEndgamesInterface $gameService,
        EndgameDefinition $definition,
        array $endgames,
        array $modeSettings,
        RuntimeAccountData $accountData,
        \DateTimeImmutable $now,
    ): ?array
    {
        $byVariant = [];
        foreach ($endgames as $endgame) {
            $byVariant[$endgame->getVariant()] = $endgame;
        }

        $toRemind = null;
        foreach ($definition->variants as $variant) {
            $endgame = $byVariant[$variant->key] ?? null;
            if ($endgame !== null && $endgame->needsReminder($modeSettings[$variant->key])) {
                $toRemind = $endgame;
                break;
            }
        }

        if ($toRemind === null) {
            return null;
        }

        // The variants of a mode share its period
        $endTime = $toRemind->getEndTime();
        if ($endTime === null) {
            $this->logger->info(sprintf(
                '[%s] End of the "%s" period unknown for profile ID %d: no reminder.',
                static::class,
                $definition->name,
                $accountData->getGameProfile()->getId()
            ));

            return null;
        }

        if (!$toRemind->endsWithin($now, $this->getDaysBeforeEnd($gameService::getEndgamesConfigKey(), $definition))) {
            return null;
        }

        $fields = [];
        foreach ($definition->variants as $variant) {
            $endgame = $byVariant[$variant->key] ?? null;
            $fields[] = $endgame === null || !$endgame->isUnlocked() || $modeSettings[$variant->key] === EndgameReminder::Off
                ? self::createFiller()
                : self::createVariantField($variant->name, $endgame);
        }
        while (count($fields) < EndgameDefinition::MAX_VARIANTS) {
            $fields[] = self::createFiller();
        }

        $fields[] = new EmbedField()
            ->setName('Ends in')
            ->setValue($gameService->getHoyolabUtils()->formatTime(max(0, $endTime->getTimestamp() - $now->getTimestamp()), 2))
            ->setIcon('🕒')
            ->setInline(true);

        return $fields;
    }

    private static function createVariantField(string $name, GameEndgame $endgame): EmbedField
    {
        if (!$endgame->isPlayed()) {
            return new EmbedField()->setName($name)->setValue('Not played yet')->setIcon('⚔️')->setInline(true);
        }

        return new EmbedField()
            ->setName($name)
            ->setValue(implode("\n", $endgame->getProgressLines()) ?: 'Played')
            // Lines with their own icons (e.g. one per floor) are not prefixed by another one
            ->setIcon($endgame->hasProgressIcons() ? null : ($endgame->isMaxed() ? '✅' : '⚔️'))
            ->setInline(true);
    }

    private static function createFiller(): EmbedField
    {
        return new EmbedField()->setName(self::FILLER)->setValue(self::FILLER)->setInline(true);
    }

    private function logUnreadableMode(EndgameDefinition $definition, RuntimeAccountData $accountData, \Throwable $e): void
    {
        $profile = $accountData->getGameProfile();
        // Class and retcode only: the message and the response body can carry account data
        $this->logger->warning(sprintf(
            '%s could not be read, skipped by the endgames reminder: %s%s',
            $definition->name,
            $e::class,
            $e instanceof HoyolabException && $e->getHoyolabRetcode() !== null ? sprintf(' (retcode %d)', $e->getHoyolabRetcode()) : ''
        ), [
            'game_profile_id' => $profile->getId(),
            'uid' => $profile->getGameUid(),
            'region' => $profile->getRegion(),
        ]);
    }

    private function getNow(): \DateTimeImmutable
    {
        return $this->clock?->now() ?? new \DateTimeImmutable();
    }
}
