<?php

namespace App\AnimeBundle\Command;

use App\AnimeBundle\Service\AnimeListChecker;
use Exception;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Scheduler\Attribute\AsCronTask;

#[AsCronTask('@midnight', arguments: ['type' => 'anime', '--tracker' => 'all'], transports: 'core_async')]
#[AsCronTask('@midnight', arguments: ['type' => 'manga', '--tracker' => 'all'], transports: 'core_async')]
#[AsCommand(name: 'anime:cache-refresh', description: 'Anime and manga tracking cache refresh')]
class AnimeCacheRefreshCommand extends Command
{
    public function __construct(
        private readonly AnimeListChecker $listChecker,
        ?string                           $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->addArgument('type', InputArgument::REQUIRED, 'Cache type to refresh, anime or manga');
        $this->addOption('tracker', 't', InputOption::VALUE_OPTIONAL, 'Target tracker (myanimelist, anilist, or all)', 'all');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $type = $input->getArgument('type');
        $trackerOption = $input->getOption('tracker');

        if ($type !== 'anime' && $type !== 'manga') {
            throw new Exception("Invalid value $type for argument type! Expected 'anime' or 'manga'.");
        }

        $trackersToRefresh = $trackerOption === 'all'
            ? $this->listChecker->getTrackers()
            : [$trackerOption => $this->listChecker->getTracker($trackerOption)];

        foreach ($trackersToRefresh as $name => $tracker) {
            $output->writeln(sprintf("Refreshing %s cache for tracker [%s]...", $type, strtoupper($name)));

            if ($type === 'anime') {
                $tracker->refreshAnimeCache();
            } else {
                $tracker->refreshMangaCache();
            }

            $output->writeln(sprintf("[%s] %s cache refreshed successfully!", strtoupper($name), $type));
        }

        $output->writeln("");
        return Command::SUCCESS;
    }
}
