<?php

namespace App\AnimeBundle\Command;

use App\AnimeBundle\Service\MyAnimeListService;
use Exception;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'anime:cache-refresh', description: 'MyAnimeList cache refresh')]
class AnimeCacheRefreshCommand extends Command
{
    public function __construct(private readonly MyAnimeListService $malService, ?string $name = null)
    {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->addArgument('type', InputArgument::REQUIRED, 'Cache type to refresh, anime or manga');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $type = $input->getArgument('type');
        if($type === 'manga') {
            $this->malService->refreshMangaCache();
            $output->writeln("");
            $output->writeln("MyAnimeList manga cache refreshed successfully!");
            $output->writeln("");
            return Command::SUCCESS;
        }
        if($type === 'anime') {
            $this->malService->refreshAnimeCache();
            $output->writeln("");
            $output->writeln("MyAnimeList anime cache refreshed successfully!");
            $output->writeln("");
            return Command::SUCCESS;
        }
        throw new Exception("Invalid value $type for argument type!");
    }

}