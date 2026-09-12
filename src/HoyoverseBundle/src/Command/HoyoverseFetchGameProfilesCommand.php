<?php

namespace App\HoyoverseBundle\Command;

use App\HoyoverseBundle\Repository\HoyoverseAccountRepository;
use App\HoyoverseBundle\Service\HoyolabManagerService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'hoyoverse:fetch-game-profiles', description: 'Hoyoverse fetch game profiles')]
class HoyoverseFetchGameProfilesCommand extends Command
{
    public function __construct(
        private readonly HoyoverseAccountRepository $accountRepo,
        private readonly HoyolabManagerService $hoyolabManagerService,
        ?string                                 $name = null)
    {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->addArgument('accountId', InputArgument::REQUIRED, "The ID of the Hoyoverse account to update");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $accountId = (int) $input->getArgument('accountId');
        $account = $this->accountRepo->find($accountId);
        if (!$account) {
            $output->writeln(sprintf('<error>Account with ID %d not found.</error>', $accountId));
            return Command::FAILURE;
        }

        $this->hoyolabManagerService->updateCachedGameProfilesByGameRecords($account);
        $output->writeln(sprintf('<info>Profiles successfully updated for account ID %d.</info>', $accountId));

        return Command::SUCCESS;
    }

}