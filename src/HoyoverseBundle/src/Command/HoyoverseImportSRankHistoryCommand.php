<?php

namespace App\HoyoverseBundle\Command;

use App\GamesBundle\Model\Pull\PullImportProgress;
use App\HoyoverseBundle\Repository\HoyoverseGameProfileRepository;
use App\HoyoverseBundle\Service\Pull\SRankHistoryImportLauncher;
use App\HoyoverseBundle\Service\Pull\SRankHistoryImportService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Exit code: failure when the profile cannot be imported, or when a banner was (or, in a dry run, would be) left
 * unchanged or rolled back, so that a script can tell.
 */
#[AsCommand(
    name: 'hoyoverse:import-s-rank-history',
    description: 'Import the official S-rank history of a game profile (Zenless Zone Zero: HoYoLAB Signal Search) into its missing pulls.'
)]
class HoyoverseImportSRankHistoryCommand extends Command
{
    public function __construct(
        private readonly HoyoverseGameProfileRepository $profileRepository,
        private readonly SRankHistoryImportService $importService,
        private readonly SRankHistoryImportLauncher $launcher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('profileId', InputArgument::REQUIRED, 'ID of the target HoyoverseGameProfile')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Read the history, check and simulate every banner, report what would change, write nothing')
            ->addOption('async', null, InputOption::VALUE_NONE, 'Queue the import on the hoyoverse_pulls Messenger transport instead of running synchronously');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $profileId = (int) $input->getArgument('profileId');
        $dryRun = (bool) $input->getOption('dry-run');
        $async = (bool) $input->getOption('async');

        $profile = $this->profileRepository->find($profileId);
        if ($profile === null) {
            $io->error(\sprintf('Game profile #%d not found.', $profileId));

            return Command::FAILURE;
        }

        $gameService = $this->importService->getSupportedGame($profile);
        if ($gameService === null) {
            $io->error(\sprintf('Profile #%d (%s) cannot import an official S-rank history.', $profileId, (string) $profile->getGameName()));

            return Command::FAILURE;
        }

        $io->title(\sprintf('Official S-Rank History Import%s', $dryRun ? ' [DRY RUN]' : ($async ? ' [ASYNC]' : '')));
        $io->definitionList(['Profile' => \sprintf('#%d — %s (%s)', $profileId, (string) $profile->getNickname(), $gameService->getGameName())]);

        if ($async) {
            if ($dryRun) {
                $io->error('--dry-run cannot be combined with --async.');

                return Command::FAILURE;
            }

            // The checks of the API endpoint: usable cookie, no S-rank history import of the profile already queued
            try {
                $operation = $this->launcher->queue($profile, $profile->getAccount()?->getUser()?->getId());
            } catch (HttpExceptionInterface $e) {
                $io->error($e->getMessage());

                return Command::FAILURE;
            }
            $io->success(\sprintf('Import queued on hoyoverse_pulls (operation ID: %s).', $operation['id']));

            return Command::SUCCESS;
        }

        try {
            $summary = $this->importService->import(
                profile: $profile,
                onProgress: static function (PullImportProgress $progress) use ($io): void {
                    if ($progress->phase === 'fetching') {
                        $io->writeln('Reading the official history...');
                    }
                },
                dryRun: $dryRun,
                blockingLock: true,
            );
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $rows = [];
        foreach ($summary->banners ?? [] as $banner) {
            $rows[] = [$banner['name'], $banner['status'], (string) ($banner['matched'] ?? 0), (string) $banner['imported'], (string) $banner['updated'], (string) ($banner['error'] ?? '')];
        }
        $io->table(['Banner', 'Status', 'Matched', $dryRun ? 'Would create' : 'Created', $dryRun ? 'Would update' : 'Updated', 'Reason'], $rows);

        if ($summary->warnings !== []) {
            $io->warning($summary->warnings);
        }

        if ($summary->skipped > 0) {
            $io->error(\sprintf(
                '%d banner(s) %s unchanged (skipped or rolled back): see the reasons above.%s',
                $summary->skipped,
                $dryRun ? 'would be left' : 'left',
                $dryRun ? ' Nothing was written.' : '',
            ));

            return Command::FAILURE;
        }
        $io->success($dryRun ? 'Dry-run completed: nothing was written.' : 'S-rank history import completed.');

        return Command::SUCCESS;
    }
}
