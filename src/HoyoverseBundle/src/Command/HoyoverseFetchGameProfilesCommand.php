<?php

namespace App\HoyoverseBundle\Command;

use App\GamesBundle\Command\AbstractFetchGameProfilesCommand;
use App\GamesBundle\Model\Game\GameAccountInterface;
use App\HoyoverseBundle\Entity\HoyoverseAccount;
use App\HoyoverseBundle\Repository\HoyoverseAccountRepository;
use App\HoyoverseBundle\Service\HoyolabManagerService;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * @extends AbstractFetchGameProfilesCommand<HoyoverseAccount>
 */
#[AsCommand(
    name: 'hoyoverse:fetch-game-profiles',
    description: 'Fetch the game profiles of HoYoverse accounts from the HoYoLAB game record cards'
)]
class HoyoverseFetchGameProfilesCommand extends AbstractFetchGameProfilesCommand
{
    public function __construct(
        private readonly HoyoverseAccountRepository $accountRepository,
        private readonly HoyolabManagerService $hoyolabManagerService,
    ) {
        parent::__construct();
    }

    protected function getProviderName(): string
    {
        return 'HoYoverse';
    }

    protected function getAccountRepository(): HoyoverseAccountRepository
    {
        return $this->accountRepository;
    }

    protected function fetchGameProfiles(GameAccountInterface $account): array
    {
        \assert($account instanceof HoyoverseAccount);

        return $this->hoyolabManagerService->updateCachedGameProfilesByGameRecords($account)->getGameProfiles()->toArray();
    }
}
