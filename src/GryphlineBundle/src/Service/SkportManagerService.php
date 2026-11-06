<?php

namespace App\GryphlineBundle\Service;

use App\GryphlineBundle\Entity\GryphlineAccount;
use App\GryphlineBundle\Entity\GryphlineGameProfile;
use App\GryphlineBundle\Exception\SkportApiException;
use App\GryphlineBundle\Model\Game\GryphlineGameInterface;
use App\GryphlineBundle\Repository\GryphlineGameProfileRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Service\ServiceCollectionInterface;

class SkportManagerService
{
    /**
     * @param ServiceCollectionInterface<GryphlineGameInterface> $locatorByGameBiz
     * @param ServiceCollectionInterface<GryphlineGameInterface> $locatorByGameId
     */
    public function __construct(
        #[Target('gryphline')]
        private readonly LoggerInterface $logger,
        private readonly EntityManagerInterface $entityManager,
        #[Target('gryphline.skport.client')]
        private readonly HttpClientInterface $skportClient,
        private readonly SkportSignerService $signerService,
        private readonly SkportAuthService $authService,
        #[AutowireLocator(services: 'gryphline.game.biz', indexAttribute: 'key')]
        protected readonly ServiceCollectionInterface $locatorByGameBiz,
        #[AutowireLocator(services: 'gryphline.game.id', indexAttribute: 'key')]
        protected readonly ServiceCollectionInterface $locatorByGameId,
    ) {
    }

    /**
     * Queries SKPort API for player game accounts and role bindings.
     *
     * @return array<string, mixed>
     */
    public function getGameBindings(GryphlineAccount $account, bool $retryOn401 = true): array
    {
        $creds = $this->authService->getOrRefreshCredentials($account);

        $path = '/api/v1/game/player/binding';
        $timestamp = (string) time();
        $sign = $this->signerService->generateSignV2($path, $timestamp, $creds->getSalt());

        $headers = [
            'cred' => $creds->getCred(),
            'timestamp' => $timestamp,
            'platform' => '3',
            'vname' => '1.0.0',
            'sk-language' => 'en',
            'sign' => $sign,
        ];

        $response = $this->skportClient->request(Request::METHOD_GET, $path, [
            'headers' => $headers,
        ]);

        if ($response->getStatusCode() === Response::HTTP_UNAUTHORIZED && $retryOn401) {
            $this->logger->warning(sprintf('SKPort session expired for account ID %d, forcing OAuth refresh...', $account->getId()));
            $this->authService->getOrRefreshCredentials($account, force: true);

            return $this->getGameBindings($account, retryOn401: false);
        }

        if ($response->getStatusCode() !== Response::HTTP_OK) {
            throw (new SkportApiException('Player Binding HTTP error: ' . $response->getStatusCode()))
                ->setApiStatusCode($response->getStatusCode());
        }

        $body = $response->toArray(false);
        if (($body['code'] ?? -1) !== 0) {
            throw (new SkportApiException('Player Binding failed: ' . ($body['message'] ?? 'Error')))
                ->setApiCode($body['code'] ?? null)
                ->setResponseBody($body);
        }

        return $body['data']['list'] ?? [];
    }

    /**
     * Updates or creates cached game profiles for a Gryphline account based on API bindings.
     *
     * @return GryphlineGameProfile[]
     */
    public function updateCachedGameProfiles(GryphlineAccount $account): array
    {
        $bindingsList = $this->getGameBindings($account);
        $profileRepo = $this->entityManager->getRepository(GryphlineGameProfile::class);
        $updatedProfiles = [];

        foreach ($bindingsList as $appItem) {
            $appCode = $appItem['appCode'] ?? throw new SkportApiException('Missing appCode in SKPort binding item.');
            $gameService = $this->locatorByGameBiz->get($appCode);

            foreach ($appItem['bindingList'] as $binding) {
                $gameId = (int) ($binding['gameId'] ?? $gameService::getGameId());

                foreach ($binding['roles'] as $role) {
                    $roleId = (string) $role['roleId'];
                    $serverId = (string) $role['serverId'];

                    $gameProfile = $profileRepo->findOneBy([
                        'account' => $account,
                        'gameId' => $gameId,
                        'gameUid' => $roleId,
                    ]);

                    if (!$gameProfile) {
                        $gameProfile = (new GryphlineGameProfile())
                            ->setAccount($account)
                            ->setGameId($gameId)
                            ->setGameBiz($gameService::getGameBiz())
                            ->setGameName($gameService->getGameName())
                            ->setGameUid($roleId)
                            ->setActive(true)
                            ->setSkportCheckIn(true)
                            ->setStaminaCheck(false)
                            ->setStaminaThreshold(-1);
                    }

                    $gameProfile
                        ->setNickname($role['nickname'])
                        ->setLevel((int) $role['level'])
                        ->setServerId($serverId)
                        ->setRegion($role['serverType'])
                        ->setRegionName($role['serverName'])
                        ->updateParsedRegion()
                        ->updateParsedTimezone();

                    $this->entityManager->persist($gameProfile);
                    $account->addGameProfile($gameProfile);
                    $updatedProfiles[] = $gameProfile;

                    $this->logger->info(sprintf(
                        'Cached Gryphline game profile: %s (UID: %s, GameId: %d, ServerId: %s, SkGameRole: %s)',
                        $gameProfile->getNickname(),
                        $gameProfile->getGameUid(),
                        $gameProfile->getGameId(),
                        $gameProfile->getServerId(),
                        $gameProfile->getSkGameRole()
                    ));
                }
            }
        }

        $this->entityManager->flush();

        return $updatedProfiles;
    }
}
