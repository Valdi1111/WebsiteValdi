<?php

namespace App\AnimeBundle\Command;

use App\AnimeBundle\Service\AnimeDownloaderLocator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Scheduler\Attribute\AsCronTask;

#[AsCronTask('*/5 * * * *', arguments: 'animeworld', transports: 'core_async')]
#[AsCommand(name: 'anime:check-new-episodes', description: 'Anime check new episodes')]
class AnimeCheckNewEpisodesCommand extends Command
{
    public function __construct(
        private readonly AnimeDownloaderLocator $locator,
        ?string                                 $name = null)
    {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->addArgument('service', InputOption::VALUE_REQUIRED, 'Anime service');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $downloader = $this->locator->get($input->getArgument('service'));
            $episodes = $downloader->checkNewEpisodes();
            $io->success(sprintf('Found %d new episodes.', count($episodes)));
        } catch (\Throwable $e) {
            $io->warning('Check skipped or partially failed: ' . $e->getMessage());
            return Command::SUCCESS;
        }
        return Command::SUCCESS;
    }

}