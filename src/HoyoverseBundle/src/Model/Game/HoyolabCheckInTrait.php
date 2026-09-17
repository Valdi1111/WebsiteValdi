<?php

namespace App\HoyoverseBundle\Model\Game;

use App\HoyoverseBundle\Exception\NoAwardsDataException;
use App\HoyoverseBundle\Exception\RetrieveAwardsDataException;
use App\HoyoverseBundle\Exception\RetrieveSignInfoException;
use App\HoyoverseBundle\Exception\SignInFailedException;
use App\HoyoverseBundle\Model\AwardData;
use App\HoyoverseBundle\Model\CheckInResult;
use App\HoyoverseBundle\Model\RuntimeAccountData;
use App\HoyoverseBundle\Model\SignInfo;
use Symfony\Component\HttpFoundation\Request;

/**
 * @mixin GameInterface
 * @mixin HasHoyolabCheckInInterface
 */
trait HoyolabCheckInTrait
{

    public function checkIn(RuntimeAccountData $account): CheckInResult
    {
        $signInfo = $this->getSignInfo($account);
        /** @var AwardData[] $awardsData */
        $awardsData = $this->getAwardsData($account);

        if ($signInfo->isSign()) {
            $this->getLogger()->info("{$account->getGameProfile()->getNickname()} already signed in today");

            return new CheckInResult()
                ->setAlreadySignedIn(true)
                ->setResult($this->getCheckInSignedMessage())
                ->setTotalSignDay($signInfo->getTotalSignDay())
                ->setAwardData($awardsData[$signInfo->getTotalSignDay() - 1]);
        }

        $this->signIn($account);
        $awardData = $awardsData[$signInfo->getTotalSignDay()];

        $this->getLogger()->info("({$account->getGameProfile()->getGameUid()}) {$account->getGameProfile()->getNickname()} Today's Reward: {$awardData->getName()} x{$awardData->getCnt()}");

        return new CheckInResult()
            ->setResult($this->getCheckInSuccessMessage())
            ->setTotalSignDay($signInfo->getTotalSignDay() + 1)
            ->setAwardData($awardData);
    }

    public function signIn(RuntimeAccountData $account): void
    {
        $this->requestHoyolab(
            method: Request::METHOD_POST,
            url: $this->getUrlSign(),
            auth: $account,
            query: ['act_id' => $this->getActId()],
            exceptionClass: SignInFailedException::class,
            extraHeaders: ['x-rpc-signgame' => $this->getSignGame()]
        );
    }

    public function getSignInfo(RuntimeAccountData $account): SignInfo
    {
        $body = $this->requestHoyolab(
            method: Request::METHOD_GET,
            url: $this->getUrlInfo(),
            auth: $account,
            query: ['act_id' => $this->getActId()],
            exceptionClass: RetrieveSignInfoException::class,
            extraHeaders: ['x-rpc-signgame' => $this->getSignGame()]
        );

        return $this->getDenormalizer()->denormalize(
            $body['data'] ?? [],
            SignInfo::class
        );
    }

    /**
     * @inheritDoc
     */
    public function getAwardsData(RuntimeAccountData $account): array
    {
        $body = $this->requestHoyolab(
            method: Request::METHOD_GET,
            url: $this->getUrlHome(),
            auth: $account,
            query: ['act_id' => $this->getActId()],
            exceptionClass: RetrieveAwardsDataException::class,
            extraHeaders: ['x-rpc-signgame' => $this->getSignGame()]
        );

        $awards = $body['data']['awards'] ?? [];
        if (empty($awards)) {
            throw new NoAwardsDataException()->setHoyolabBody($body);
        }

        return $this->getDenormalizer()->denormalize(
            $awards,
            AwardData::class . '[]'
        );
    }

}