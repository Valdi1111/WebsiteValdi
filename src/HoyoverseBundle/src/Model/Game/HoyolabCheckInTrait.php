<?php

namespace App\HoyoverseBundle\Model\Game;

use App\GamesBundle\Model\Game\CachedProfileData;
use App\GamesBundle\Model\Game\CheckInStatus;
use App\GamesBundle\Model\Game\HasCheckInStatusInterface;
use App\GamesBundle\Model\Game\RuntimeAccountDataInterface;
use App\HoyoverseBundle\Exception\NoAwardsDataException;
use App\HoyoverseBundle\Exception\RetrieveAwardsDataException;
use App\HoyoverseBundle\Exception\RetrieveSignInfoException;
use App\HoyoverseBundle\Exception\SignInFailedException;
use App\GamesBundle\Model\Game\AwardData;
use App\GamesBundle\Model\Game\CheckInResult;
use App\HoyoverseBundle\Model\RuntimeAccountData;
use App\HoyoverseBundle\Model\SignInfo;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * @mixin GameInterface
 * @mixin HasHoyolabCheckInInterface
 * @mixin HasCheckInStatusInterface
 */
trait HoyolabCheckInTrait
{
    private ?CacheInterface $checkInStatusCache = null;

    /**
     * Own clock: the notes clock of the game services is private to the subclasses that use the notes, which this
     * trait (used by GameService) cannot read, and HI3/ToT have no notes at all.
     */
    private ?ClockInterface $checkInStatusClock = null;

    public function getCheckInStatusCache(): ?CacheInterface
    {
        return $this->checkInStatusCache;
    }

    #[Required]
    public function setCheckInStatusCache(
        #[Target('hoyoverse.check_in_status.cache')]
        CacheInterface $cache
    ): void
    {
        $this->checkInStatusCache = $cache;
    }

    #[Required]
    public function setCheckInStatusClock(ClockInterface $clock): void
    {
        $this->checkInStatusClock = $clock;
    }

    public function checkIn(RuntimeAccountData $account): CheckInResult
    {
        $signInfo = $this->getSignInfo($account);
        /** @var AwardData[] $awardsData */
        $awardsData = $this->getAwardsData($account);

        if ($signInfo->isSign()) {
            $this->forgetCheckInStatus($account);
            $this->getLogger()->info("{$account->getGameProfile()->getNickname()} already signed in today");

            return new CheckInResult()
                ->setAlreadySignedIn(true)
                ->setResult($this->getCheckInSignedMessage())
                ->setTotalSignDay($signInfo->getTotalSignDay())
                ->setAwardData($awardsData[$signInfo->getTotalSignDay() - 1]);
        }

        $this->signIn($account);
        // Right after the claim, before anything else can fail: the status must not keep showing "not signed"
        $this->forgetCheckInStatus($account);
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
        $body = $this->requestSignInfo($account);

        return $this->getDenormalizer()->denormalize(
            $body['data'] ?? [],
            SignInfo::class
        );
    }

    public function getCheckInStatus(RuntimeAccountDataInterface $account, bool $fresh = false): CheckInStatus
    {
        if (!$account instanceof RuntimeAccountData) {
            throw new \InvalidArgumentException(sprintf('Expected %s, got %s', RuntimeAccountData::class, get_debug_type($account)));
        }

        $entry = CachedProfileData::get(
            $this->checkInStatusCache,
            $this->getCheckInStatusKey($account),
            $fresh,
            fn (): array => $this->fetchCheckInStatusData($account),
            $this->checkInStatusClock
        );
        $data = $entry['data'];
        $totalSignDay = $data['total_sign_day'] ?? null;

        return new CheckInStatus(
            // Anything but a real true reads as not signed: never show an unknown check-in as done
            signedToday: ($data['is_sign'] ?? null) === true,
            totalDays: is_int($totalSignDay) ? $totalSignDay : null,
            fetchedAt: CachedProfileData::fetchedAt($entry, $this->checkInStatusClock),
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

    /**
     * Read-only check-in info request (never the sign one), shared by getSignInfo() and getCheckInStatus().
     *
     * @return array<string, mixed> the whole HoYoLAB answer
     */
    private function requestSignInfo(RuntimeAccountData $account): array
    {
        return $this->requestHoyolab(
            method: Request::METHOD_GET,
            url: $this->getUrlInfo(),
            auth: $account,
            query: ['act_id' => $this->getActId()],
            exceptionClass: RetrieveSignInfoException::class,
            extraHeaders: ['x-rpc-signgame' => $this->getSignGame()]
        );
    }

    /**
     * @return array{0: array<string, mixed>, 1: bool} the check-in info payload, and whether it may be cached
     */
    private function fetchCheckInStatusData(RuntimeAccountData $account): array
    {
        $body = $this->requestSignInfo($account);
        $data = $body['data'] ?? null;

        // An answer without retcode passes requestHoyolab() but is not a confirmed success: used, never cached
        return [is_array($data) ? $data : [], ($body['retcode'] ?? null) === 0 && is_array($data)];
    }

    /**
     * Drops the cached check-in status of the profile, so the next read shows the claim at once.
     */
    private function forgetCheckInStatus(RuntimeAccountData $account): void
    {
        $key = $this->getCheckInStatusKey($account);
        if ($key !== null) {
            $this->checkInStatusCache?->delete($key);
        }
    }

    /**
     * Cache key of the profile, null for an unsaved profile (one shared key would mix several profiles).
     */
    private function getCheckInStatusKey(RuntimeAccountData $account): ?string
    {
        $profileId = $account->getGameProfile()->getId();

        return $profileId !== null ? 'check_in_' . $profileId : null;
    }

}
