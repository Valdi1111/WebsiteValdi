<?php

namespace App\HoyoverseBundle\MessageHandler\Profile;

use App\CoreBundle\Model\Notification\UniversalEmbed;
use App\GamesBundle\Message\ProfileTaskMessageInterface;
use App\GamesBundle\Model\Endgame\GameEndgame;
use App\GamesBundle\Model\Game\HasEndgamesInterface;
use App\HoyoverseBundle\Message\Profile\EndgamesReminderProfileMessage;
use App\HoyoverseBundle\Model\Game\GameInterface;
use App\HoyoverseBundle\Model\RuntimeAccountData;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * Reminds the endgame modes ending within the configured number of days (hoyoverse.endgames_reminder.days_before_end)
 * that are not played yet or, with the profile's endgamesFullClear setting, not at the maximum yet.
 *
 * @implements AbstractProfileTaskMessageHandler<EndgamesReminderProfileMessage>
 */
#[AsMessageHandler]
class EndgamesReminderProfileHandler extends AbstractProfileTaskMessageHandler
{
    public const int DEFAULT_DAYS_BEFORE_END = 7;

    private ?ClockInterface $clock = null;

    private int $daysBeforeEnd = self::DEFAULT_DAYS_BEFORE_END;

    #[Required]
    public function setClock(ClockInterface $clock): void
    {
        $this->clock = $clock;
    }

    public function getDaysBeforeEnd(): int
    {
        return $this->daysBeforeEnd;
    }

    #[Required]
    public function setDaysBeforeEnd(
        #[Autowire(param: 'hoyoverse.endgames_reminder.days_before_end')]
        int $daysBeforeEnd
    ): void
    {
        $this->daysBeforeEnd = $daysBeforeEnd;
    }

    public function __invoke(EndgamesReminderProfileMessage $message): void
    {
        $this->handleProfile($message);
    }

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
        $untilFullClear = (bool) $accountData->getGameProfile()->isEndgamesFullClear();

        $endgames = [];
        foreach ($gameService->getEndgamesData($accountData) as $endgame) {
            if (!$endgame->needsReminder($untilFullClear)) {
                continue;
            }

            if ($endgame->getEndTime() === null) {
                $this->logger->info(sprintf(
                    '[%s] End of the "%s" period unknown for profile ID %d: no reminder.',
                    static::class,
                    $endgame->getName(),
                    $accountData->getGameProfile()->getId()
                ));
                continue;
            }

            if ($endgame->endsWithin($now, $this->getDaysBeforeEnd())) {
                $endgames[] = $endgame;
            }
        }

        if ($endgames === []) {
            return;
        }

        $this->sendNotificationToAccountData($accountData, $this->createEndgamesNotification($accountData, $endgames, $now));
    }

    /**
     * @param iterable<GameEndgame> $endgames modes to remind, already filtered
     */
    public function createEndgamesNotification(RuntimeAccountData $accountData, iterable $endgames, \DateTimeInterface $now): UniversalEmbed
    {
        $notification = $this->createNotificationWithAccountData($accountData, "Endgames Reminder")
            ->setDescription("Don't Forget to Do Your Endgames!");

        foreach ($endgames as $endgame) {
            $notification->addField($endgame->getName() ?? 'Endgame', $this->formatEndgame($endgame, $now), icon: "⚔️");
        }

        return $notification;
    }

    private function formatEndgame(GameEndgame $endgame, \DateTimeInterface $now): string
    {
        $lines = [];
        if (!$endgame->isPlayed()) {
            $lines[] = 'Not played yet';
        } else {
            $lines[] = $endgame->getFormattedProgress();
            foreach ($endgame->getStages() as $stage) {
                if (!$stage->isMaxed()) {
                    $lines[] = $stage->getFormattedOutput();
                }
            }
        }

        $endTime = $endgame->getEndTime();
        if ($endTime !== null) {
            $lines[] = sprintf(
                'Ends in %s (%s UTC%s)',
                $this->formatRemaining($endTime->getTimestamp() - $now->getTimestamp()),
                $endTime->format('Y-m-d H:i'),
                $endTime->format('P')
            );
        }

        return implode("\n", $lines);
    }

    private function formatRemaining(int $seconds): string
    {
        $seconds = max(0, $seconds);
        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);

        if ($days === 0) {
            return $hours === 0 ? 'less than 1 hr' : "{$hours} hr";
        }

        return sprintf('%d %s, %d hr', $days, $days === 1 ? 'day' : 'days', $hours);
    }

    private function getNow(): \DateTimeImmutable
    {
        return $this->clock?->now() ?? new \DateTimeImmutable();
    }
}
