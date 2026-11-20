<?php

namespace App\GryphlineBundle\Command;

use App\CoreBundle\Model\Pull\PullImportProgress;
use App\GryphlineBundle\Message\Profile\ImportPullsBackupMessage;
use App\GryphlineBundle\Model\Game\GameInterface;
use App\GryphlineBundle\Repository\GryphlineGameProfileRepository;
use App\GryphlineBundle\Service\Pull\PullBackupImporterManager;
use App\GryphlineBundle\Service\Pull\PullBackupImportService;
use App\GryphlineBundle\Service\Pull\PullOperationTracker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'gryphline:import-pulls',
    description: 'Import pull history from a third-party backup file into a GryphlineGameProfile.',
)]
class GryphlineImportPullsCommand extends Command
{
    /**
     * @param ServiceLocator<GameInterface> $locatorByGameBiz
     */
    public function __construct(
        private readonly GryphlineGameProfileRepository $profileRepository,
        private readonly PullBackupImporterManager $importerManager,
        private readonly PullBackupImportService $importService,
        private readonly PullOperationTracker $operationTracker,
        private readonly MessageBusInterface $bus,
        #[AutowireLocator(services: 'gryphline.game.biz', indexAttribute: 'key')]
        private readonly ServiceLocator $locatorByGameBiz,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('profileId', InputArgument::REQUIRED, 'Target GryphlineGameProfile ID')
            ->addArgument('importer', InputArgument::REQUIRED, 'Importer key')
            ->addArgument('file', InputArgument::REQUIRED, 'Absolute or relative path to the backup file')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Validate, parse, and compute deduplication counts without writing to the database')
            ->addOption('async', null, InputOption::VALUE_NONE, 'Dispatch the import onto the gryphline_pulls Messenger queue instead of running synchronously');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $profileId = (int) $input->getArgument('profileId');
        $importerKey = trim((string) $input->getArgument('importer'));
        $fileArg = (string) $input->getArgument('file');
        $dryRun = (bool) $input->getOption('dry-run');
        $async = (bool) $input->getOption('async');

        $profile = $this->profileRepository->find($profileId);
        if ($profile === null) {
            $io->error(sprintf('GryphlineGameProfile #%d not found.', $profileId));

            return Command::FAILURE;
        }

        $filePath = realpath($fileArg) ?: $fileArg;
        if (!is_file($filePath) || !is_readable($filePath)) {
            $io->error(sprintf('Backup file "%s" does not exist or is not readable.', $fileArg));

            return Command::FAILURE;
        }

        $gameBiz = (string) $profile->getGameBiz();
        if (!$this->locatorByGameBiz->has($gameBiz)) {
            $io->error(sprintf('Unsupported game service for game_biz "%s".', $gameBiz));

            return Command::FAILURE;
        }

        $gameService = $this->locatorByGameBiz->get($gameBiz);
        $importer = $this->importerManager->getByKey($importerKey);
        if ($importer === null || !$this->importerManager->supportsGameService($importer, $gameService)) {
            $available = array_map(
                static fn ($imp): string => $imp::getKey(),
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

        $validation = $importer->validate($filePath);
        if (!$validation->valid) {
            $io->error(sprintf('Validation failed: %s', $validation->error ?? 'Invalid format.'));

            return Command::FAILURE;
        }

        $io->title(sprintf(
            'Gryphline Pull Backup Import%s',
            $dryRun ? ' [DRY-RUN]' : ($async ? ' [ASYNC]' : '')
        ));
        $io->definitionList(
            ['Profile' => sprintf('#%d — %s (%s, UID %s)', $profile->getId(), $profile->getNickname(), $profile->getGameName(), $profile->getGameUid())],
            ['Importer' => sprintf('%s (%s)', $importer->getLabel(), $importerKey)],
            ['File' => $filePath],
            ['Estimated Pulls' => (string) $validation->estimatedCount],
        );

        if ($async) {
            if ($dryRun) {
                $io->error('Cannot combine --async with --dry-run.');

                return Command::INVALID;
            }

            $operation = $this->operationTracker->startBackupImport(
                profileId: (int) $profile->getId(),
                userId: $profile->getAccount()?->getUser()?->getId(),
                importerKey: $importerKey,
                importerLabel: $importer->getLabel(),
                estimatedTotal: $validation->estimatedCount,
            );

            $this->bus->dispatch(new ImportPullsBackupMessage(
                operationId: (string) $operation['id'],
                gameProfileId: (int) $profile->getId(),
                importerKey: $importerKey,
                filePath: $filePath,
                deleteFileAfter: false,
            ));

            $io->success(sprintf('Backup import queued on gryphline_pulls (operation ID: %s).', $operation['id']));

            return Command::SUCCESS;
        }

        $progressBar = null;
        $summary = $this->importService->import(
            profile: $profile,
            importerKey: $importerKey,
            filePath: $filePath,
            onProgress: static function (PullImportProgress $progress) use ($output, &$progressBar): void {
                if ($progress->phase === 'importing' && $progress->total > 0) {
                    if ($progressBar === null) {
                        $progressBar = new ProgressBar($output, $progress->total);
                        $progressBar->start();
                    }
                    $progressBar->setProgress($progress->processed);
                }
            },
            dryRun: $dryRun,
            blockingLock: true,
        );

        if ($progressBar !== null) {
            $progressBar->finish();
            $io->newLine(2);
        }

        if ($summary->warnings !== []) {
            $io->warning(array_slice($summary->warnings, 0, 10));
        }

        $io->success(sprintf(
            '%s complete: %d parsed, %d %s, %d skipped (%d warning(s)).',
            $dryRun ? 'Dry-run' : 'Import',
            $summary->total,
            $summary->imported,
            $dryRun ? 'would be imported' : 'imported',
            $summary->skipped,
            count($summary->warnings)
        ));

        return Command::SUCCESS;
    }
}
