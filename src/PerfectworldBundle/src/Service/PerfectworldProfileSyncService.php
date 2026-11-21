<?php

namespace App\PerfectworldBundle\Service;

use App\PerfectworldBundle\Entity\PerfectworldAccount;
use App\PerfectworldBundle\Entity\PerfectworldGameProfile;
use App\PerfectworldBundle\Exception\PerfectworldAuthException;
use App\PerfectworldBundle\Model\Game\GameInterface;
use App\PerfectworldBundle\Model\Sdk\PwRole;
use App\PerfectworldBundle\Service\Catalog\NteCatalog;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Contracts\Service\ServiceCollectionInterface;

/**
 * Creates/updates the game profiles of an account from the SDK role list.
 */
class PerfectworldProfileSyncService
{
    /**
     * The SDK app id used for login (3000001) is the NTE one: every role it lists is an NTE role.
     */
    private const int SDK_GAME_ID = NevernessToEvernessService::GAME_ID;

    /**
     * @param ServiceCollectionInterface<GameInterface> $locatorByGameId
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PerfectworldSdkClient $sdkClient,
        private readonly NteCatalog $catalog,
        #[AutowireLocator(services: 'perfectworld.game.id', indexAttribute: 'key')]
        private readonly ServiceCollectionInterface $locatorByGameId,
    ) {
    }

    /**
     * @param list<PwRole>|null $roles Roles already fetched (e.g. while validating a token)
     *
     * @throws PerfectworldAuthException when the token is invalid (the account is flagged and flushed first)
     */
    public function syncProfiles(PerfectworldAccount $account, ?array $roles = null): void
    {
        if ($roles === null) {
            try {
                $roles = $this->sdkClient->getRoles((string) $account->getPwUid(), (string) $account->getToken());
            } catch (PerfectworldAuthException $e) {
                $account->setTokenInvalid(true);
                $this->entityManager->flush();

                throw $e;
            }
        }

        /** @var GameInterface $game */
        $game = $this->locatorByGameId->get(self::SDK_GAME_ID);

        $existing = [];
        foreach ($account->getGameProfiles() as $profile) {
            $existing[(string) $profile->getGameUid()] = $profile;
        }

        foreach ($roles as $role) {
            $profile = $existing[$role->roleId] ?? null;
            if ($profile === null) {
                $profile = (new PerfectworldGameProfile())->setGameUid($role->roleId);
                $account->addGameProfile($profile);
                $this->entityManager->persist($profile);
            }

            $region = $game->getRegionForServer($role->serverId);
            $profile
                ->setGameId($game::getGameId())
                ->setGameBiz($game::getGameBiz())
                ->setGameName($game->getGameName())
                ->setNickname($role->roleName)
                ->setLevel($role->level)
                ->setServerId($role->serverId)
                ->setRegionName($role->serverName ?? $region)
                ->setRegion($region)
                ->setParsedRegion($region)
                ->setParsedTimezone($game->getTimezoneForRegion($region))
                ->setAvatarId($role->avatar)
                ->setIconUrl($this->catalog->findAvatarIconUrl($role->avatar) ?? $game->getIcon())
                ->setFirstLoginAt(self::parseSdkTime($role->firstLogin));
        }

        $account
            ->setTokenInvalid(false)
            ->setLastSyncedAt(new \DateTimeImmutable());

        $this->entityManager->flush();
    }

    private static function parseSdkTime(?string $value): ?\DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        $dateTime = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);

        return $dateTime !== false ? $dateTime : null;
    }
}
