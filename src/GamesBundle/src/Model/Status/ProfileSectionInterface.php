<?php

namespace App\GamesBundle\Model\Status;

use App\CoreBundle\Model\Notification\UniversalEmbed;
use App\GamesBundle\Model\Game\GameProfileInterface;
use App\GamesBundle\Model\Profile\GameProfileContext;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * One kind of data shown for a game profile (stamina, dailies, expeditions...): a game supports it through a capability
 * of its service, and adding a kind of data means adding a section. Sections are ordered by the priority of their tag
 * (#[AsTaggedItem(priority: ...)]), highest first.
 *
 * collect() reads the data without any threshold or "all done" check; present() and summarize() lay it out without
 * calling any API.
 *
 * @template T of object
 */
#[AutoconfigureTag(self::TAG)]
interface ProfileSectionInterface
{
    public const string TAG = 'games.profile_section';

    /**
     * Stable key (stamina, dailies...), used by commands and settings.
     */
    public function getKey(): string;

    public function getLabel(): string;

    public function getIcon(): string;

    public function supports(GameProfileContext $context): bool;

    /**
     * Whether the data comes from the real-time notes: the collector reads them once per profile before the sections,
     * and a failed read fails every notes section of the profile without more calls.
     */
    public function usesNotes(): bool;

    /**
     * Whether the compact view of a profile (/status, daily summary) shows the section. Sections with their own costly
     * reads (endgames: one battle record call per mode) are left to their own command.
     */
    public function inSummary(): bool;

    /**
     * @return T
     * @throws \Throwable when the data cannot be read
     */
    public function collect(GameProfileContext $context): object;

    /**
     * Full view of the data, on its own embed.
     *
     * @param T $data
     */
    public function present(GameProfileInterface $profile, object $data): UniversalEmbed;

    /**
     * One line for the compact view of a profile (/status, daily summary).
     *
     * @param T $data
     */
    public function summarize(GameProfileInterface $profile, object $data): SummaryLine;
}
