<?php

namespace App\GryphlineBundle\Command;

use App\GamesBundle\Command\AbstractFetchGameProfilesCommand;
use App\GamesBundle\Model\Game\GameAccountInterface;
use App\GryphlineBundle\Entity\GryphlineAccount;
use App\GryphlineBundle\Entity\GryphlineGameProfile;
use App\GryphlineBundle\Repository\GryphlineAccountRepository;
use App\GryphlineBundle\Service\SkportManagerService;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * @extends AbstractFetchGameProfilesCommand<GryphlineAccount>
 */
#[AsCommand(
    name: 'gryphline:fetch-game-profiles',
    description: 'Fetch the game profiles of Gryphline accounts from the SKPort game bindings'
)]
class GryphlineFetchGameProfilesCommand extends AbstractFetchGameProfilesCommand
{
    public function __construct(
        private readonly GryphlineAccountRepository $accountRepository,
        private readonly SkportManagerService $skportManagerService,
    ) {
        parent::__construct();
    }

    protected function getProviderName(): string
    {
        return 'Gryphline';
    }

    protected function getAccountRepository(): GryphlineAccountRepository
    {
        return $this->accountRepository;
    }

    protected function fetchGameProfiles(GameAccountInterface $account): array
    {
        \assert($account instanceof GryphlineAccount);

        $this->skportManagerService->updateCachedGameProfiles($account);

        return $account->getGameProfiles()->toArray();
    }

    protected function getExtraColumns(): array
    {
        return [
            'GameId' => static fn (GryphlineGameProfile $profile): ?int => $profile->getGameId(),
            'SkGameRole' => static fn (GryphlineGameProfile $profile): string => $profile->getSkGameRole(),
            'Server' => static fn (GryphlineGameProfile $profile): ?string => $profile->getRegionName(),
        ];
    }
}
