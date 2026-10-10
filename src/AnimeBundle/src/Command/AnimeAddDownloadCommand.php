<?php

namespace App\AnimeBundle\Command;

use App\AnimeBundle\Exception\UnsupportedWebsiteException;
use App\AnimeBundle\Model\EpisodeDownloadRequest;
use App\AnimeBundle\Service\EpisodeDownloadManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'anime:add-download', description: 'Anime add download')]
class AnimeAddDownloadCommand extends Command
{
    public function __construct(
        private readonly EpisodeDownloadManager $downloadManager,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->addArgument('url', InputArgument::REQUIRED, 'Full anime link (with hostname)');
        $this->addOption('all', 'a', InputOption::VALUE_NONE, 'Download the entire series');
        $this->addOption('no-filter', 'f', InputOption::VALUE_NONE, 'Ignore the anime tracking cache filter');
        $this->addOption('simulate', 's', InputOption::VALUE_NONE, 'Simulate the download without saving to database');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $downloadReq = new EpisodeDownloadRequest()
            ->setUrl($input->getArgument('url'))
            ->setAll($input->getOption('all'))
            ->setFilter(!$input->getOption('no-filter'))
            ->setSave(!$input->getOption('simulate'));

        try {
            $episodes = $this->downloadManager->processDownloadRequest($downloadReq);
        } catch (UnsupportedWebsiteException $e) {
            $output->writeln("<error>No service found for the given url.</error>");
            return Command::FAILURE;
        }

        if (!count($episodes)) {
            $output->writeln("");
            $output->writeln("No episodes found!");
            $output->writeln("");
            return Command::SUCCESS;
        }

        $output->writeln("");
        $output->writeln(count($episodes) . " episodes found!");
        $output->writeln("");
        foreach ($episodes as $episode) {
            $output->writeln($episode->getEpisode() . " - " . $episode->getFile());
        }
        $output->writeln("");

        return Command::SUCCESS;
    }
}
