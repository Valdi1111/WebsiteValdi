<?php

namespace App\AnimeBundle\Command;

use App\AnimeBundle\Service\EpisodeDownloadManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Scheduler\Attribute\AsCronTask;

#[AsCronTask('*/5 * * * *', arguments: ['service' => 'animeworld'], transports: 'core_async')]
#[AsCommand(name: 'anime:check-new-episodes', description: 'Anime check new episodes')]
class AnimeCheckNewEpisodesCommand extends Command
{
    public function __construct(
        private readonly EpisodeDownloadManager $downloadManager,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->addArgument('service', InputArgument::REQUIRED, 'Anime service');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $service = $input->getArgument('service');

        try {
            $episodes = $this->downloadManager->checkNewEpisodes($service);
            $io->success(sprintf('Found %d new episodes.', count($episodes)));
        } catch (\Throwable $e) {
            $io->warning('Check skipped or partially failed: ' . $e->getMessage());
            return Command::SUCCESS;
        }

        return Command::SUCCESS;
    }
}
