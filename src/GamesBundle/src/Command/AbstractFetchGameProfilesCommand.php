<?php

namespace App\GamesBundle\Command;

use App\GamesBundle\Model\Game\GameAccountInterface;
use App\GamesBundle\Model\Game\GameProfileInterface;
use Doctrine\Persistence\ObjectRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Base of the "<provider>:fetch-game-profiles" commands: fetches the game profiles of one account, or of every
 * account of the provider when no id is given, from the provider API, and prints the game profiles of the account.
 * A failing account does not stop the others.
 *
 * Exit code: failure when the account is not found or when the fetch failed for at least one account.
 *
 * No constructor here: each provider command promotes its own repository and service.
 *
 * @template TAccount of GameAccountInterface
 */
abstract class AbstractFetchGameProfilesCommand extends Command
{
    /**
     * Provider name used in the messages (e.g. "HoYoverse").
     */
    abstract protected function getProviderName(): string;

    /**
     * @return ObjectRepository<TAccount>
     */
    abstract protected function getAccountRepository(): ObjectRepository;

    /**
     * Fetches the game profiles of the account from the provider API and stores them.
     *
     * @param TAccount $account
     *
     * @return array<GameProfileInterface> The game profiles of the account after the fetch
     */
    abstract protected function fetchGameProfiles(GameAccountInterface $account): array;

    /**
     * Provider-specific columns appended to the profile table.
     *
     * @return array<string, callable(GameProfileInterface): mixed> Column header => value of the profile
     */
    protected function getExtraColumns(): array
    {
        return [];
    }

    protected function configure(): void
    {
        $this->addArgument(
            'accountId',
            InputArgument::OPTIONAL,
            \sprintf('ID of the %s account to update (all accounts when omitted)', $this->getProviderName())
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $provider = $this->getProviderName();
        $accountId = $input->getArgument('accountId');

        if ($accountId !== null) {
            $account = $this->getAccountRepository()->find((int) $accountId);
            if ($account === null) {
                $io->error(\sprintf('%s account #%d not found.', $provider, (int) $accountId));

                return Command::FAILURE;
            }
            $accounts = [$account];
        } else {
            $accounts = $this->getAccountRepository()->findAll();
        }

        if ($accounts === []) {
            $io->warning(\sprintf('No %s accounts found.', $provider));

            return Command::SUCCESS;
        }

        $io->title(\sprintf('Fetching the game profiles of %d %s account(s)', \count($accounts), $provider));

        $failed = 0;
        foreach ($accounts as $account) {
            $io->section(\sprintf('Account #%d', $account->getId()));

            try {
                $profiles = array_values($this->fetchGameProfiles($account));
            } catch (\Throwable $e) {
                ++$failed;
                $io->error(\sprintf('Failed to fetch the game profiles of account #%d: %s', $account->getId(), $e->getMessage()));

                continue;
            }

            if ($profiles === []) {
                $io->warning(\sprintf('Account #%d has no game profiles.', $account->getId()));

                continue;
            }

            $io->success(\sprintf('Account #%d has %d game profile(s).', $account->getId(), \count($profiles)));
            $this->renderProfiles($io, $profiles);
        }

        if ($failed > 0) {
            $io->error(\sprintf('The fetch failed for %d of %d account(s).', $failed, \count($accounts)));

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * @param list<GameProfileInterface> $profiles
     */
    private function renderProfiles(SymfonyStyle $io, array $profiles): void
    {
        $extraColumns = $this->getExtraColumns();

        $rows = [];
        foreach ($profiles as $profile) {
            $row = [
                $profile->getId() ?? 'N/A',
                $profile->getGameName() ?? 'N/A',
                $profile->getGameUid() ?? 'N/A',
                $profile->getNickname() ?? 'N/A',
                $profile->getLevel() ?? 'N/A',
                $profile->getParsedRegion() ?? 'N/A',
                $profile->getParsedTimezone() ?? 'N/A',
            ];
            foreach ($extraColumns as $value) {
                $row[] = $value($profile) ?? 'N/A';
            }
            $rows[] = $row;
        }

        $io->table(
            ['ID', 'Game', 'UID', 'Nickname', 'Level', 'Region', 'Timezone', ...array_keys($extraColumns)],
            $rows
        );
    }
}
