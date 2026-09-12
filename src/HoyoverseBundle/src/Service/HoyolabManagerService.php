<?php

namespace App\HoyoverseBundle\Service;

use App\HoyoverseBundle\Entity\HoyoverseAccount;
use App\HoyoverseBundle\Entity\HoyoverseGameProfile;
use App\HoyoverseBundle\Exception\NoGameRecordsException;
use App\HoyoverseBundle\Exception\NoGameRolesException;
use App\HoyoverseBundle\Exception\RetrieveGameRecordsException;
use App\HoyoverseBundle\Exception\RetrieveGameRolesException;
use App\HoyoverseBundle\Model\Game\HoyolabTrait;
use App\HoyoverseBundle\Model\Game\GameInterface;
use App\HoyoverseBundle\Model\GameRecord;
use App\HoyoverseBundle\Model\ParsedCookie;
use App\HoyoverseBundle\Model\UserGameRole;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\Service\ServiceCollectionInterface;

class HoyolabManagerService
{
    use HoyolabTrait;

    /**
     * @param LoggerInterface $hoyoverseLogger
     * @param ObjectMapperInterface $objectMapper
     * @param EntityManagerInterface $entityManager
     * @param ServiceCollectionInterface<GameInterface> $locatorByGameBiz
     * @param ServiceCollectionInterface<GameInterface> $locatorByGameId
     */
    public function __construct(
        private readonly LoggerInterface              $hoyoverseLogger,
        private readonly ObjectMapperInterface        $objectMapper,
        private readonly EntityManagerInterface       $entityManager,
        #[AutowireLocator(services: 'hoyoverse.game', defaultIndexMethod: 'getGameBiz')]
        protected readonly ServiceCollectionInterface $locatorByGameBiz,
        #[AutowireLocator(services: 'hoyoverse.game', defaultIndexMethod: 'getGameId')]
        protected readonly ServiceCollectionInterface $locatorByGameId,
    )
    {
    }

    public function getUrlGameRoles(): string
    {
        return "https://sg-public-api.hoyoverse.com/binding/api/getUserGameRolesByCookie";
    }

    /**
     * @param ParsedCookie $cookie
     * @return UserGameRole[]
     */
    public function getGameRoles(ParsedCookie $cookie): array
    {
        try {
            $response = $this->getHoyolabClient()->request(Request::METHOD_GET, $this->getUrlGameRoles(), [
                'headers' => [
                    'Cookie' => (string) $cookie,
                ],
            ]);

            $statusCode = $response->getStatusCode();
            $body = $response->toArray(false);

            if ($statusCode !== Response::HTTP_OK) {
                $this->getLogger()->error("Failed to retrieve game roles", [
                    'status' => $statusCode,
                    'body' => $body,
                ]);

                throw new RetrieveGameRolesException("Failed to retrieve game roles")
                    ->setHoyolabStatusCode($statusCode)
                    ->setHoyolabBody($body);
            }

            $retcode = $body['retcode'] ?? null;
            if ($retcode !== 0) {
                $this->getLogger()->error("Game roles returned non-zero retcode", [
                    'retcode' => $retcode,
                    'message' => $body['message'] ?? null,
                    'body' => $body,
                ]);

                throw new RetrieveGameRolesException("Game roles returned non-zero retcode")
                    ->setHoyolabRetcode($retcode)
                    ->setHoyolabMessage($body['message'] ?? null)
                    ->setHoyolabBody($body);
            }

            $data = $body['data'] ?? [];
            $list = $data['list'] ?? [];

            if (empty($list)) {
                $this->getLogger()->error("No game roles available", [
                    'body' => $body,
                ]);

                throw new NoGameRolesException()
                    ->setHoyolabBody($body);
            }

            return $this->getDenormalizer()->denormalize($list, UserGameRole::class . '[]');

        } catch (ExceptionInterface $e) {
            $this->getLogger()->error("Exception during game roles retrieval", [
                'error' => $e->getMessage(),
            ]);

            throw new RetrieveGameRolesException("Exception during game roles retrieval: {$e->getMessage()}");
        }
    }

    public function updateCachedGameProfilesByGameRoles(HoyoverseAccount $account): HoyoverseAccount
    {
        $parsedCookie = $this->cookieUtils->parseCookie($account->getCookie());
        $gameRoles = $this->getGameRoles($parsedCookie);

        $gameProfileRepo = $this->entityManager->getRepository(HoyoverseGameProfile::class);
        foreach ($gameRoles as $gameRole) {
            $gameProfile = $gameProfileRepo->findOneBy([
                "gameBiz" => $gameRole->getGameBiz(),
                "gameUid" => $gameRole->getGameUid()
            ]);
            if (!$gameProfile) {
                $gameProfile = new HoyoverseGameProfile();
                $gameService = $this->locatorByGameBiz->get($gameRole->getGameBiz());
                $gameProfile->setGameId($gameService::getGameId());
                $gameProfile->setGameName($gameService->getGameName());
            }

            $this->objectMapper->map($gameRole, $gameProfile);
            $gameProfile->updateParsedRegion();
            $gameProfile->updateParsedTimezone();

            $account->addGameProfile($gameProfile);
        }

        $this->entityManager->flush();

        return $account;
    }

    public function getUrlGameRecords(): string
    {
        return "https://bbs-api-os.hoyolab.com/game_record/card/wapi/getGameRecordCard";
    }

    /**
     * @param ParsedCookie $cookie
     * @return GameRecord[]
     */
    public function getGameRecords(ParsedCookie $cookie): array
    {
        try {
            $response = $this->getHoyolabClient()->request(Request::METHOD_GET, $this->getUrlGameRecords(), [
                'query' => [
                    'uid' => $cookie->getLtuidV2(),
                ],
                'headers' => [
                    'Cookie' => (string) $cookie,
                ],
            ]);

            $statusCode = $response->getStatusCode();
            $body = $response->toArray(false);

            if ($statusCode !== Response::HTTP_OK) {
                $this->getLogger()->error("Failed to retrieve game records", [
                    'status' => $statusCode,
                    'body' => $body,
                ]);

                throw new RetrieveGameRecordsException("Failed to retrieve game records")
                    ->setHoyolabStatusCode($statusCode)
                    ->setHoyolabBody($body);
            }

            $retcode = $body['retcode'] ?? null;
            if ($retcode !== 0) {
                $this->getLogger()->error("Game records returned non-zero retcode", [
                    'retcode' => $retcode,
                    'message' => $body['message'] ?? null,
                    'body' => $body,
                ]);

                throw new RetrieveGameRecordsException("Game records returned non-zero retcode")
                    ->setHoyolabRetcode($retcode)
                    ->setHoyolabMessage($body['message'] ?? null)
                    ->setHoyolabBody($body);
            }

            $data = $body['data'] ?? [];
            $list = $data['list'] ?? [];

            if (empty($list)) {
                $this->getLogger()->error("No game records available", [
                    'body' => $body,
                ]);

                throw new NoGameRecordsException()
                    ->setHoyolabBody($body);
            }

            return $this->getDenormalizer()->denormalize($list, GameRecord::class . '[]');

        } catch (ExceptionInterface $e) {
            $this->getLogger()->error("Exception during game records retrieval", [
                'error' => $e->getMessage(),
            ]);

            throw new RetrieveGameRecordsException("Exception during game records retrieval: {$e->getMessage()}");
        }
    }

    public function updateCachedGameProfilesByGameRecords(HoyoverseAccount $account): HoyoverseAccount
    {
        $parsedCookie = $this->cookieUtils->parseCookie($account->getCookie());
        $gameRecords = $this->getGameRecords($parsedCookie);

        $gameProfileRepo = $this->entityManager->getRepository(HoyoverseGameProfile::class);
        foreach ($gameRecords as $gameRecord) {
            $gameProfile = $gameProfileRepo->findOneBy([
                "gameId" => $gameRecord->getGameId(),
                "gameUid" => $gameRecord->getGameUid()
            ]);
            if (!$gameProfile) {
                $gameProfile = new HoyoverseGameProfile();
                $gameService = $this->locatorByGameId->get($gameRecord->getGameId());
                $gameProfile->setGameBiz($gameService::getGameBiz());
            }

            $this->objectMapper->map($gameRecord, $gameProfile);
            $gameProfile->updateParsedRegion();
            $gameProfile->updateParsedTimezone();

            $account->addGameProfile($gameProfile);
        }

        $this->entityManager->flush();

        return $account;
    }

}