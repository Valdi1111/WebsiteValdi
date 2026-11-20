<?php

namespace App\HoyoverseBundle\Command;

use App\HoyoverseBundle\Message\Profile\ImportPullsBackupMessage;
use App\HoyoverseBundle\Model\Game\GameInterface;
use App\CoreBundle\Model\Pull\PullImportProgress;
use App\HoyoverseBundle\Repository\HoyoverseGameProfileRepository;
use App\HoyoverseBundle\Service\Pull\PullBackupImporterManager;
use App\HoyoverseBundle\Service\Pull\PullBackupImportService;
use App\HoyoverseBundle\Service\Pull\PullOperationTracker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Service\ServiceCollectionInterface;

#[AsCommand(
    name: 'hoyoverse:import-pulls',
    description: 'Import historical HoYoverse pulls from a third-party tracker backup file (paimon.moe, starrailstation.com, zzz.rng.moe).'
)]
class HoyoverseImportPullsCommand extends Command
{
    /**
     * @param ServiceCollectionInterface<GameInterface> $locatorByGameId
     */
    public function __construct(
        private readonly HoyoverseGameProfileRepository $profileRepository,
        private readonly PullBackupImporterManager $importerManager,
        private readonly PullBackupImportService $importService,
        private readonly PullOperationTracker $operationTracker,
        private readonly MessageBusInterface $bus,
        #[AutowireLocator(services: 'hoyoverse.game.id', indexAttribute: 'key')]
        private readonly ServiceCollectionInterface $locatorByGameId,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('profileId', InputArgument::REQUIRED, 'ID of the target HoyoverseGameProfile')
            ->addArgument('importer', InputArgument::REQUIRED, 'Importer key (e.g. paimon_moe, star_rail_station_csv, zzz_rng_moe)')
            ->addArgument('file', InputArgument::REQUIRED, 'Path to the backup file')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Validate, parse, and simulate deduplication without writing to DB')
            ->addOption('async', null, InputOption::VALUE_NONE, 'Queue the import on the hoyoverse_pulls Messenger transport instead of running synchronously');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $profileId = (int) $input->getArgument('profileId');
        $importerKey = trim((string) $input->getArgument('importer'));
        $filePath = (string) $input->getArgument('file');
        $dryRun = (bool) $input->getOption('dry-run');
        $async = (bool) $input->getOption('async');

        $profile = $this->profileRepository->find($profileId);
        if ($profile === null) {
            $io->error(sprintf('Game profile #%d not found.', $profileId));

            return Command::FAILURE;
        }

        $gameId = (int) $profile->getGameId();
        $gameService = $this->locatorByGameId->has($gameId) ? $this->locatorByGameId->get($gameId) : null;
        if ($gameService === null) {
            $io->error(sprintf('Unsupported game ID "%d" on profile #%d.', $gameId, $profileId));

            return Command::FAILURE;
        }

        $importer = $this->importerManager->getByKey($importerKey);
        if ($importer === null || !$this->importerManager->supportsGameService($importer, $gameService)) {
            $available = array_map(
                static fn ($imp) => $imp::getKey(),
                $this->importerManager->getForGameService($gameService)
            );
            $io->error(sprintf(
                'Importer "%s" is not valid for %s. Available importer(s): %s',
                $importerKey,
                $gameService->getGameName(),
                $available !== [] ? implode(', ', $available) : 'none'
            ));

            return Command::FAILURE;
        }

        if (!is_file($filePath) || !is_readable($filePath)) {
            $io->error(sprintf('File "%s" does not exist or is not readable.', $filePath));

            return Command::FAILURE;
        }

        $validation = $importer->validate($filePath);
        if (!$validation->valid) {
            $io->error(sprintf('Validation failed: %s', $validation->error ?? 'Invalid file format.'));

            return Command::FAILURE;
        }

        $io->title(sprintf(
            'HoYoverse Pull Backup Import%s',
            $dryRun ? ' [DRY RUN]' : ($async ? ' [ASYNC]' : '')
        ));
        $io->definitionList(
            ['Profile' => sprintf('#%d — %s (%s, UID %s)', $profile->getId(), $profile->getNickname(), $profile->getGameName(), $profile->getGameUid())],
            ['Importer' => sprintf('%s (%s)', $importer->getLabel(), $importerKey)],
            ['File' => $filePath],
            ['Estimated records' => (string) $validation->estimatedCount],
        );

        if ($async) {
            if ($dryRun) {
                $io->error('--dry-run cannot be combined with --async.');

                return Command::FAILURE;
            }

            $operation = $this->operationTracker->startBackupImport(
                (int) $profile->getId(),
                $profile->getAccount()?->getUser()?->getId(),
                $importerKey,
                $importer->getLabel(),
                $validation->estimatedCount
            );

            $this->bus->dispatch(new ImportPullsBackupMessage(
                operationId: (string) $operation['id'],
                gameProfileId: (int) $profile->getId(),
                importerKey: $importerKey,
                filePath: realpath($filePath) ?: $filePath,
                deleteFileAfter: false,
            ));

            $io->success(sprintf('Import queued on hoyoverse_pulls (operation ID: %s).', $operation['id']));

            return Command::SUCCESS;
        }

        $progressBar = null;

        try {
            $summary = $this->importService->import(
                profile: $profile,
                importerKey: $importerKey,
                filePath: $filePath,
                onProgress: function (PullImportProgress $progress) use ($output, &$progressBar): void {
                    if ($progress->phase === 'importing') {
                        if ($progressBar === null) {
                            $progressBar = new ProgressBar($output, max(1, $progress->total));
                            $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% — %message%');
                            $progressBar->setMessage('Starting...');
                            $progressBar->start();
                        }
                        $progressBar->setMessage(sprintf('imported: %d, skipped: %d', $progress->imported, $progress->skipped));
                        $progressBar->setProgress($progress->processed);
                    }
                },
                dryRun: $dryRun,
                blockingLock: true,
            );

            if ($progressBar !== null) {
                $progressBar->setMessage(sprintf('imported: %d, skipped: %d', $summary->imported, $summary->skipped));
                $progressBar->finish();
                $output->writeln('');
            }
        } catch (\Throwable $e) {
            if ($progressBar !== null) {
                $output->writeln('');
            }
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->table(
            ['Metric', 'Count'],
            [
                ['Total parsed', (string) $summary->total],
                [$dryRun ? 'Would import' : 'Imported', (string) $summary->imported],
                ['Skipped (duplicates)', (string) $summary->skipped],
                ['Warnings', (string) count($summary->warnings)],
            ]
        );

        if ($summary->warnings !== []) {
            $io->warning($summary->warnings);
        }

        $io->success($dryRun ? 'Dry-run completed.' : 'Backup import completed.');

        return Command::SUCCESS;
    }
}
