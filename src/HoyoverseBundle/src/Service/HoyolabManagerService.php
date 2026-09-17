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
use Symfony\Contracts\Service\ServiceCollectionInterface;

class HoyolabManagerService
{
    use HoyolabTrait;

    /**
     * @param LoggerInterface $hoyoverseLogger
     * @param EntityManagerInterface $entityManager
     * @param ServiceCollectionInterface<GameInterface> $locatorByGameBiz
     * @param ServiceCollectionInterface<GameInterface> $locatorByGameId
     */
    public function __construct(
        private readonly LoggerInterface              $hoyoverseLogger,
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
        $body = $this->requestHoyolab(
            method: Request::METHOD_GET,
            url: $this->getUrlGameRoles(),
            auth: $cookie,
            query: [],
            exceptionClass: RetrieveGameRolesException::class
        );

        $list = $body['data']['list'] ?? [];
        if (empty($list)) {
            throw new NoGameRolesException()->setHoyolabBody($body);
        }

        return $this->getDenormalizer()->denormalize(
            $list,
            UserGameRole::class . '[]'
        );
    }

    public function updateCachedGameProfilesByGameRoles(HoyoverseAccount $account): HoyoverseAccount
    {
        $parsedCookie = $this->cookieUtils->parseCookie($account->getCookie());
        $gameRoles = $this->getGameRoles($parsedCookie);

        $gameProfileRepo = $this->entityManager->getRepository(HoyoverseGameProfile::class);
        foreach ($gameRoles as $gameRole) {
            $gameProfile = $gameProfileRepo->findOneBy([
                "account" => $account,
                "gameBiz" => $gameRole->getGameBiz(),
            ]);
            if (!$gameProfile) {
                $gameProfile = new HoyoverseGameProfile();
                $gameService = $this->locatorByGameBiz->get($gameRole->getGameBiz());
                $gameProfile->setGameId($gameService::getGameId());
                $gameProfile->setGameName($gameService->getGameName());
            }

            $this->getObjectMapper()->map($gameRole, $gameProfile);
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
        $body = $this->requestHoyolab(
            method: Request::METHOD_GET,
            url: $this->getUrlGameRecords(),
            auth: $cookie,
            query: [
                'uid' => $cookie->getLtuidV2(),
            ],
            exceptionClass: RetrieveGameRecordsException::class
        );

        $list = $body['data']['list'] ?? [];
        if (empty($list)) {
            throw new NoGameRecordsException()->setHoyolabBody($body);
        }

        return $this->getDenormalizer()->denormalize(
            $list,
            GameRecord::class . '[]'
        );
    }

    public function updateCachedGameProfilesByGameRecords(HoyoverseAccount $account): HoyoverseAccount
    {
        $parsedCookie = $this->cookieUtils->parseCookie($account->getCookie());
        $gameRecords = $this->getGameRecords($parsedCookie);

        $gameProfileRepo = $this->entityManager->getRepository(HoyoverseGameProfile::class);
        foreach ($gameRecords as $gameRecord) {
            $gameProfile = $gameProfileRepo->findOneBy([
                "account" => $account,
                "gameId" => $gameRecord->getGameId(),
            ]);
            if (!$gameProfile) {
                $gameProfile = new HoyoverseGameProfile();
                $gameService = $this->locatorByGameId->get($gameRecord->getGameId());
                $gameProfile->setGameBiz($gameService::getGameBiz());
            }

            $this->getObjectMapper()->map($gameRecord, $gameProfile);
            $gameProfile->updateParsedRegion();
            $gameProfile->updateParsedTimezone();

            $account->addGameProfile($gameProfile);
        }

        $this->entityManager->flush();

        return $account;
    }

}