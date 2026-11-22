<?php

namespace App\PerfectworldBundle\Command;

use App\GamesBundle\Command\AbstractFetchGameProfilesCommand;
use App\GamesBundle\Model\Game\GameAccountInterface;
use App\PerfectworldBundle\Entity\PerfectworldAccount;
use App\PerfectworldBundle\Repository\PerfectworldAccountRepository;
use App\PerfectworldBundle\Service\PerfectworldProfileSyncService;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * An expired token flags the account (token_invalid) before the failure is reported, as the sync endpoint does.
 *
 * @extends AbstractFetchGameProfilesCommand<PerfectworldAccount>
 */
#[AsCommand(
    name: 'perfectworld:fetch-game-profiles',
    description: 'Fetch the game profiles of Perfect World accounts from the SDK role list'
)]
class PerfectworldFetchGameProfilesCommand extends AbstractFetchGameProfilesCommand
{
    public function __construct(
        private readonly PerfectworldAccountRepository $accountRepository,
        private readonly PerfectworldProfileSyncService $profileSyncService,
    ) {
        parent::__construct();
    }

    protected function getProviderName(): string
    {
        return 'Perfect World';
    }

    protected function getAccountRepository(): PerfectworldAccountRepository
    {
        return $this->accountRepository;
    }

    protected function fetchGameProfiles(GameAccountInterface $account): array
    {
        \assert($account instanceof PerfectworldAccount);

        $this->profileSyncService->syncProfiles($account);

        return $account->getGameProfiles()->toArray();
    }
}
