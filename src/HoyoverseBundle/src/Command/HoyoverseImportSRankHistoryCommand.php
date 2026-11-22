<?php

namespace App\HoyoverseBundle\Command;

use App\GamesBundle\Model\Pull\PullImportProgress;
use App\HoyoverseBundle\Message\Profile\ImportSRankHistoryMessage;
use App\HoyoverseBundle\Repository\HoyoverseGameProfileRepository;
use App\HoyoverseBundle\Service\Pull\PullOperationTracker;
use App\HoyoverseBundle\Service\Pull\SRankHistoryImportService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'hoyoverse:import-s-rank-history',
    description: 'Import the official S-rank history of a game profile (Zenless Zone Zero: HoYoLAB Signal Search) into its missing pulls.'
)]
class HoyoverseImportSRankHistoryCommand extends Command
{
    public function __construct(
        private readonly HoyoverseGameProfileRepository $profileRepository,
        private readonly SRankHistoryImportService $importService,
        private readonly PullOperationTracker $operationTracker,
        private readonly MessageBusInterface $bus,
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

            $operation = $this->operationTracker->startSRankHistoryImport(
                $profileId,
                $profile->getAccount()?->getUser()?->getId(),
                $gameService->getGachaConfig()->getBannerTypes(),
            );
            $this->bus->dispatch(new ImportSRankHistoryMessage((string) $operation['id'], $profileId));
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
            $io->caution(\sprintf('%d banner(s) left unchanged: see the reasons above.', $summary->skipped));
        }
        $io->success($dryRun ? 'Dry-run completed: nothing was written.' : 'S-rank history import completed.');

        return Command::SUCCESS;
    }
}
