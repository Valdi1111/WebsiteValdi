<?php

namespace App\GamesBundle\Service\Status;

use App\GamesBundle\Model\Game\GameProfileInterface;
use App\GamesBundle\Model\Game\HasNotesInterface;
use App\GamesBundle\Model\Profile\AccountHealth;
use App\GamesBundle\Model\Profile\GameProfileContext;
use App\GamesBundle\Model\Profile\GameProfileRef;
use App\GamesBundle\Model\Status\FetchedDataInterface;
use App\GamesBundle\Model\Status\GameProfileStatus;
use App\GamesBundle\Model\Status\ProfileSectionInterface;
use App\GamesBundle\Model\Status\SectionResult;
use App\GamesBundle\Service\Profile\GameProfileRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Reads the sections of game profiles, for any caller that shows them (chat commands, summaries, games:status).
 *
 * Per profile, the notes are read once before the sections (the data getters then share the notes cache), and a
 * failed read fails every notes section of the profile without more calls: an expired cookie costs one request, not
 * one per section. Any other failure is kept to its section, and one profile never stops the others. Errors are
 * logged here and kept in the results, never shown as they are.
 */
class GameProfileStatusCollector
{
    /** @var array<string, ProfileSectionInterface> */
    private array $sections = [];

    /**
     * @param iterable<ProfileSectionInterface> $sections in display order (tag priority)
     */
    public function __construct(
        private readonly GameProfileRegistry $registry,
        #[AutowireIterator(ProfileSectionInterface::TAG)]
        iterable $sections,
        private readonly LoggerInterface $logger,
    ) {
        foreach ($sections as $section) {
            $this->sections[$section->getKey()] = $section;
        }
    }

    /**
     * @return array<string, ProfileSectionInterface> every section, in display order
     */
    public function getSections(): array
    {
        return $this->sections;
    }

    /**
     * @return list<string> the sections of the compact view of a profile (/status, daily summary), in display order
     */
    public function getSummarySectionKeys(): array
    {
        return array_keys(array_filter($this->sections, static fn (ProfileSectionInterface $section): bool => $section->inSummary()));
    }

    /**
     * Sections the profile supports, without reading any data: the context of a provider is built without API calls.
     *
     * Asked for every profile the site lists, so nothing is logged: a profile whose context cannot be built (unreadable
     * cookie, game without a service) has no section here, and its status would show the error anyway.
     *
     * @return list<string> section keys, in display order
     */
    public function getSupportedSectionKeys(GameProfileInterface $profile): array
    {
        return $this->findSupportedSectionKeys($profile) ?? [];
    }

    /**
     * Same as getSupportedSectionKeys(), but null when the context cannot be built: such a profile (unreadable cookie)
     * must not be hidden from the chat commands, whose answer tells the user to log in again.
     *
     * @return list<string>|null section keys in display order, null when unknown
     */
    public function findSupportedSectionKeys(GameProfileInterface $profile): ?array
    {
        try {
            $context = $this->registry->providerOf($profile)?->createContext($profile);
        } catch (\Throwable) {
            return null;
        }
        if ($context === null) {
            return null;
        }

        return array_keys(array_filter($this->sections, static fn (ProfileSectionInterface $section): bool => $section->supports($context)));
    }

    /**
     * @param list<GameProfileInterface> $profiles   profiles of registered providers, already checked by the caller
     * @param list<string>|null          $sectionKeys sections to read, null for all; results keep the display order
     * @param bool                       $fresh      skip the caches (notes...) and read the APIs again
     * @return list<GameProfileStatus> one per profile, in the order given
     * @throws \InvalidArgumentException for an unknown section key, or a profile of no registered provider
     */
    public function collect(array $profiles, ?array $sectionKeys = null, bool $fresh = false): array
    {
        $sections = $this->sections;
        if ($sectionKeys !== null) {
            $unknown = array_diff($sectionKeys, array_keys($this->sections));
            if ($unknown !== []) {
                throw new \InvalidArgumentException(sprintf('Unknown section "%s".', implode('", "', $unknown)));
            }
            $sections = array_intersect_key($this->sections, array_flip($sectionKeys));
        }

        return array_map(fn (GameProfileInterface $profile): GameProfileStatus => $this->collectProfile($profile, $sections, $fresh), $profiles);
    }

    /**
     * @param array<string, ProfileSectionInterface> $sections
     */
    private function collectProfile(GameProfileInterface $profile, array $sections, bool $fresh): GameProfileStatus
    {
        $provider = $this->registry->providerOf($profile);
        if ($provider === null || $profile->getId() === null) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a saved profile of a registered game provider.', $profile::class));
        }
        $ref = new GameProfileRef($provider->getKey(), $profile->getId());
        $account = $profile->getAccount();
        $health = $account !== null ? $provider->getAccountHealth($account) : AccountHealth::ok();

        try {
            $context = $provider->createContext($profile, $fresh);
        } catch (\Throwable $e) {
            $this->logFailure('The game profile could not be read', $ref, null, $e);

            return new GameProfileStatus($profile, $ref, $provider->describe($profile), $health, error: $e);
        }

        $supported = array_filter($sections, static fn (ProfileSectionInterface $section): bool => $section->supports($context));
        [$fetchedAt, $notesError] = $this->readNotes($context, $supported);

        $results = [];
        foreach ($supported as $key => $section) {
            if ($notesError !== null && $section->usesNotes()) {
                $results[$key] = SectionResult::failure($section, $notesError);
                continue;
            }
            try {
                $results[$key] = SectionResult::success($section, $section->collect($context));
            } catch (\Throwable $e) {
                $this->logFailure('A section of the game profile could not be read', $ref, $key, $e);
                $results[$key] = SectionResult::failure($section, $e);
            }
        }

        // The oldest data of the profile, so a view never shows cached data as newer than it is
        foreach ($results as $result) {
            $dataFetchedAt = $result->data instanceof FetchedDataInterface ? $result->data->getFetchedAt() : null;
            if ($dataFetchedAt !== null && ($fetchedAt === null || $dataFetchedAt < $fetchedAt)) {
                $fetchedAt = $dataFetchedAt;
            }
        }

        return new GameProfileStatus($profile, $ref, $context->game, $health, $results, $fetchedAt);
    }

    /**
     * Reads the notes once when a section needs them, with $fresh applied here only: the getters of the sections then
     * read the entry this call stored.
     *
     * @param array<string, ProfileSectionInterface> $sections
     * @return array{0: ?\DateTimeImmutable, 1: ?\Throwable} the fetch time, or the error of the read
     */
    private function readNotes(GameProfileContext $context, array $sections): array
    {
        $usesNotes = array_filter($sections, static fn (ProfileSectionInterface $section): bool => $section->usesNotes()) !== [];
        if (!$usesNotes || !$context->gameService instanceof HasNotesInterface || $context->accountData === null) {
            return [null, null];
        }

        try {
            return [$context->gameService->getNotes($context->accountData, $context->fresh)->getFetchedAt(), null];
        } catch (\Throwable $e) {
            $this->logFailure('The notes of the game profile could not be read', $context->ref, null, $e);

            return [null, $e];
        }
    }

    /**
     * Class and code only, like the endgames reminder: provider messages and response bodies can carry account data.
     * games:status -v prints the messages.
     */
    private function logFailure(string $message, GameProfileRef $ref, ?string $section, \Throwable $e): void
    {
        $this->logger->warning($message . ': {error}', [
            'profile' => (string) $ref,
            'section' => $section,
            'error' => $e::class,
            'code' => $e->getCode(),
        ]);
    }
}
