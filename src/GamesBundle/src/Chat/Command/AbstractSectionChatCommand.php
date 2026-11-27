<?php

namespace App\GamesBundle\Chat\Command;

use App\CoreBundle\Model\Chat\ChatUser;
use App\CoreBundle\Model\Notification\UniversalEmbed;
use App\GamesBundle\Model\Chat\ChatProfileSelection;
use App\GamesBundle\Model\Status\GameProfileStatus;
use App\GamesBundle\Model\Status\SectionResult;
use App\GamesBundle\Presenter\GameProfileEmbedFactory;
use App\GamesBundle\Presenter\ProfileSummaryPresenter;
use App\GamesBundle\Service\Status\GameProfileStatusCollector;
use Psr\Log\LoggerInterface;

/**
 * A game chat command showing one section in full (/stamina, /dailies...): the full view of the section per profile,
 * as games:status --full --section does.
 *
 * The profiles of games without the section are left out; an unreadable profile is kept, with the compact view that
 * says why (the generic error and the account problem), and a failed section gets a generic line, never the error.
 */
abstract class AbstractSectionChatCommand extends AbstractProfileChatCommand
{
    public function __construct(
        private readonly GameProfileStatusCollector $collector,
        private readonly ProfileSummaryPresenter $summaryPresenter,
        private readonly GameProfileEmbedFactory $embedFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Key of the section the command shows (ProfileSectionInterface::getKey()).
     */
    abstract protected function getSectionKey(): string;

    protected function getSectionKeys(): array
    {
        return [$this->getSectionKey()];
    }

    protected function buildEmbeds(ChatUser $user, ChatProfileSelection $selection, bool $fresh): array
    {
        $key = $this->getSectionKey();
        $embeds = [];
        foreach ($this->collector->collect($selection->profiles, [$key], $fresh) as $status) {
            if ($status->error !== null) {
                $embeds[] = $this->summaryPresenter->present($status);
            } elseif (isset($status->sections[$key])) {
                $embeds[] = $this->present($status, $status->sections[$key]);
            }
        }

        return $embeds;
    }

    private function present(GameProfileStatus $status, SectionResult $result): UniversalEmbed
    {
        $section = $result->section;
        if (!$result->isSuccessful() || $result->data === null) {
            return $this->unavailable($status, $result);
        }

        try {
            $embed = $section->present($status->profile, $result->data);
        } catch (\Throwable $e) {
            // One section laid out wrong (unexpected data) never fails the other profiles of the answer
            $this->logger->warning('A section of the game profile could not be shown: {error}', [
                'profile' => (string) $status->ref,
                'section' => $section->getKey(),
                'error' => $e::class,
                'code' => $e->getCode(),
            ]);

            return $this->unavailable($status, $result);
        }
        // The time of the data rather than of the answer, so the age of cached notes shows
        if ($status->fetchedAt !== null) {
            $embed->setTimestamp($status->fetchedAt);
        }

        return $embed;
    }

    /**
     * The generic line, and the account problem when there is one: an expired session is the usual cause, and the user
     * can fix it.
     */
    private function unavailable(GameProfileStatus $status, SectionResult $result): UniversalEmbed
    {
        $lines = ['⚠️ ' . ProfileSummaryPresenter::UNAVAILABLE];
        if (!$status->accountHealth->ok) {
            $lines[] = '⚠️ ' . $status->accountHealth->problem;
        }

        return $this->embedFactory->create($status->profile, $result->section->getLabel(), implode("\n", $lines));
    }
}
