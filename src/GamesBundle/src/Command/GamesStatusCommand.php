<?php

namespace App\GamesBundle\Command;

use App\CoreBundle\Entity\User;
use App\CoreBundle\Model\Notification\UniversalEmbed;
use App\CoreBundle\Repository\UserRepository;
use App\GamesBundle\Model\Game\GameProfileInterface;
use App\GamesBundle\Model\Profile\GameProfileRef;
use App\GamesBundle\Model\Status\GameProfileStatus;
use App\GamesBundle\Presenter\ProfileSummaryPresenter;
use App\GamesBundle\Service\Profile\GameProfileRegistry;
use App\GamesBundle\Service\Status\GameProfileStatusCollector;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Live data of the game profiles of a user, read and laid out as the chat commands do: a development and support
 * tool for the profile status layer. It calls the game APIs (through the notes cache unless --fresh).
 */
#[AsCommand(name: 'games:status', description: 'Show the live data of the game profiles of a user (stamina, dailies...)')]
class GamesStatusCommand extends Command
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly GameProfileRegistry $registry,
        private readonly GameProfileStatusCollector $collector,
        private readonly ProfileSummaryPresenter $summaryPresenter,
    ) {
        parent::__construct();
    }

    /**
     * @param list<string> $ref
     * @param list<string> $section
     */
    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Id or email of the user')]
        string $user,
        #[Option('Only the profiles of this game (slug or alias: gi, hsr, zzz, ake...)')]
        ?string $game = null,
        // Not "--profile": a global option of the console
        #[Option('Only this profile, as "<provider>:<id>" (e.g. "hoyoverse:42")')]
        array $ref = [],
        #[Option('Only this section (stamina, dailies...)')]
        array $section = [],
        #[Option('Skip the caches and call the game APIs again')]
        bool $fresh = false,
        #[Option('Show each section in full instead of the summary of each profile')]
        bool $full = false,
    ): int {
        $owner = ctype_digit($user)
            ? $this->userRepository->find((int) $user)
            : $this->userRepository->findOneBy(['email' => mb_strtolower(trim($user))]);
        if (!$owner instanceof User) {
            $io->error(sprintf('No user "%s".', $user));

            return Command::INVALID;
        }

        $unknownSections = array_diff($section, array_keys($this->collector->getSections()));
        if ($unknownSections !== []) {
            $io->error(sprintf('Unknown section "%s". Sections: %s.', implode('", "', $unknownSections), implode(', ', array_keys($this->collector->getSections()))));

            return Command::INVALID;
        }

        $profiles = $this->findProfiles($io, $owner, $game, $ref);
        if ($profiles === null) {
            return Command::INVALID;
        }
        if ($profiles === []) {
            $io->warning(sprintf('%s has no active game profile%s.', $owner->getEmail(), $game !== null ? ' of this game' : ''));

            return Command::SUCCESS;
        }

        $statuses = $this->collector->collect($profiles, $section !== [] ? array_values($section) : null, $fresh);
        foreach ($statuses as $status) {
            $full ? $this->renderFull($io, $status) : $this->renderEmbed($io, $this->summaryPresenter->present($status));
            if ($io->isVerbose()) {
                $this->renderErrors($io, $status);
            }
        }

        $failed = array_filter($statuses, static fn (GameProfileStatus $status): bool => !$status->isSuccessful());
        if ($failed !== []) {
            $io->warning(sprintf('%d of %d profiles could not be read entirely%s.', count($failed), count($statuses), $io->isVerbose() ? '' : ' (run with -v for the errors)'));

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * @param list<string> $refs
     * @return list<GameProfileInterface>|null null after an error message
     */
    private function findProfiles(SymfonyStyle $io, User $owner, ?string $gameName, array $refs): ?array
    {
        $game = null;
        if ($gameName !== null) {
            $game = $this->registry->findGame($gameName, $owner);
            if ($game === null) {
                $slugs = array_map(static fn ($game): string => $game->slug, $this->registry->getGames($owner));
                $io->error(sprintf('Unknown game "%s" for this user. Games: %s.', $gameName, implode(', ', $slugs) ?: 'none'));

                return null;
            }
        }
        if ($refs === []) {
            return $this->registry->findProfilesOf($owner, $game);
        }

        $profiles = [];
        foreach ($refs as $value) {
            $ref = GameProfileRef::tryFromString($value);
            $profile = $ref !== null ? $this->registry->resolve($owner, $ref) : null;
            if ($profile === null) {
                $io->error(sprintf('No profile "%s" for this user (expected "<provider>:<id>", e.g. "hoyoverse:42").', $value));

                return null;
            }
            if ($game === null || $profile->getGameBiz() === $game->gameBiz) {
                $profiles[] = $profile;
            }
        }

        return $profiles;
    }

    private function renderFull(SymfonyStyle $io, GameProfileStatus $status): void
    {
        $io->title(sprintf('%s · %s', $status->game?->name ?? $status->profile->getGameName(), $status->ref));
        if ($status->error !== null || $status->sections === []) {
            $this->renderEmbed($io, $this->summaryPresenter->present($status));

            return;
        }
        foreach ($status->sections as $result) {
            if ($result->isSuccessful()) {
                $this->renderEmbed($io, $result->section->present($status->profile, $result->data));
            } else {
                $io->section($result->section->getLabel());
                $io->text('⚠️ ' . ProfileSummaryPresenter::UNAVAILABLE);
            }
        }
    }

    private function renderEmbed(SymfonyStyle $io, UniversalEmbed $embed): void
    {
        $io->section((string) $embed->getTitle());
        if ($embed->getDescription() !== null) {
            $io->text(explode("\n", $embed->getDescription()));
        }
        $fields = [];
        foreach ($embed->getFields() as $field) {
            $fields[] = [trim(($field->getIcon() ?? '') . ' ' . $field->getName()) => $field->getValue()];
        }
        if ($fields !== []) {
            $io->definitionList(...$fields);
        }
        $io->text(sprintf('<comment>%s · %s</comment>', $embed->getFooterText(), $embed->getTimestamp()?->format(\DateTimeInterface::ATOM)));
    }

    private function renderErrors(SymfonyStyle $io, GameProfileStatus $status): void
    {
        $errors = [];
        if ($status->error !== null) {
            $errors[] = sprintf('%s: %s: %s', $status->ref, $status->error::class, $status->error->getMessage());
        }
        foreach ($status->sections as $key => $result) {
            if ($result->error !== null) {
                $errors[] = sprintf('%s %s: %s: %s', $status->ref, $key, $result->error::class, $result->error->getMessage());
            }
        }
        if ($errors !== []) {
            $io->error($errors);
        }
    }
}
