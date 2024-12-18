<?php

namespace App\AnimeBundle\Command;

use App\AnimeBundle\Message\EpisodeDownloadNotification;
use App\AnimeBundle\Service\AnimeDownloaderLocator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(name: 'anime:add-download', description: 'Anime add download')]
class AnimeAddDownloadCommand extends Command
{
    public function __construct(
        private readonly AnimeDownloaderLocator $locator,
        private readonly MessageBusInterface    $bus,
        string                                  $name = null)
    {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->addArgument('url', InputArgument::REQUIRED, 'Full anime link (with hostname)');
        $this->addOption('all', 'a', InputOption::VALUE_NONE, 'Download the entire series');
        $this->addOption('no-filter', 'f', InputOption::VALUE_NONE, 'Ignore the MyAnimeList cache filter');
        $this->addOption('simulate', 's', InputOption::VALUE_NONE, 'Simulate the download without saving to database');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $url = $input->getArgument('url');
        $all = $input->getOption('all');
        $noFilter = $input->getOption('no-filter');
        $simulate = $input->getOption('simulate');

        $this->locator->parseUrl($url);
        $downloader = $this->locator->getService();
        $episodes = $downloader->createEpisodeDownloads($this->locator->getUrlPath(), $all, !$noFilter, !$simulate);
        if (!count($episodes)) {
            $output->writeln("");
            $output->writeln("No episodes found!");
            $output->writeln("");
            return Command::FAILURE;
        }
        $output->writeln("");
        $output->writeln(count($episodes) . " episodes found!");
        $output->writeln("");
        foreach ($episodes as $episode) {
            $output->writeln($episode->getEpisode() . " - " . $episode->getFile());
            if (!$simulate) {
                $this->bus->dispatch(new EpisodeDownloadNotification($episode->getId()));
            }
        }
        $output->writeln("");
        return Command::SUCCESS;
    }

}