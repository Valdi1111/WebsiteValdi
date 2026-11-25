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
use App\HoyoverseBundle\Exception\SignInRiskException;
use App\GamesBundle\Model\Game\AwardData;
use App\GamesBundle\Model\Game\CheckInResult;
use App\HoyoverseBundle\Model\RuntimeAccountData;
use App\HoyoverseBundle\Model\SignInfo;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Serializer\Exception\PartialDenormalizationException;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
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
                // No award for a month total of 0 (index -1) or a day past the award list
                ->setAwardData($awardsData[$signInfo->getTotalSignDay() - 1] ?? null);
        }

        // A failed or captcha-blocked claim throws here and keeps the cached status, which still reads "not signed"
        $this->signIn($account);
        // Right after the claim, before anything else can fail: the status must not keep showing "not signed"
        $this->forgetCheckInStatus($account);
        // The claim went through: nothing may throw from here on, a day past the award list just has no award
        $awardData = $awardsData[$signInfo->getTotalSignDay()] ?? null;
        $reward = $awardData !== null ? "{$awardData->getName()} x{$awardData->getCnt()}" : 'not in the award list';

        $this->getLogger()->info("({$account->getGameProfile()->getGameUid()}) {$account->getGameProfile()->getNickname()} Today's Reward: {$reward}");

        return new CheckInResult()
            ->setResult($this->getCheckInSuccessMessage())
            ->setTotalSignDay($signInfo->getTotalSignDay() + 1)
            ->setAwardData($awardData);
    }

    public function signIn(RuntimeAccountData $account): void
    {
        $body = $this->requestHoyolab(
            method: Request::METHOD_POST,
            url: $this->getUrlSign(),
            auth: $account,
            query: ['act_id' => $this->getActId()],
            exceptionClass: SignInFailedException::class,
            extraHeaders: ['x-rpc-signgame' => $this->getSignGame()]
        );

        $risk = self::findSignRisk($body);
        if ($risk !== null) {
            $riskCode = $risk['risk_code'] ?? null;
            $riskCode = is_int($riskCode) || is_string($riskCode) ? $riskCode : null;
            $profile = $account->getGameProfile();

            $this->getLogger()->warning("({$profile->getGameUid()}) {$profile->getNickname()} check-in blocked by a HoYoLAB captcha: reward not claimed", [
                'uid'       => $profile->getGameUid(),
                'region'    => $profile->getRegion(),
                'risk_code' => $riskCode,
            ]);

            throw new SignInRiskException($riskCode)
                ->setHoyolabRetcode(is_int($body['retcode'] ?? null) ? $body['retcode'] : null)
                ->setHoyolabMessage(is_string($body['message'] ?? null) ? $body['message'] : null)
                ->setHoyolabBody($body);
        }
    }

    public function getSignInfo(RuntimeAccountData $account): SignInfo
    {
        $body = $this->requestSignInfo($account);

        $data = $body['data'] ?? null;
        try {
            $signInfo = $this->getDenormalizer()->denormalize(
                is_array($data) ? $data : [],
                SignInfo::class,
                context: [DenormalizerInterface::COLLECT_DENORMALIZATION_ERRORS => true]
            );
        } catch (PartialDenormalizationException $e) {
            // A malformed field is left unset: harmless for the optional ones, caught below for the sign state
            $signInfo = $e->getData();
        }

        // Never guess the sign state: a guessed "not signed" would claim, a guessed "signed" would skip the claim
        if (!$signInfo instanceof SignInfo || !$signInfo->hasSignState()) {
            throw new RetrieveSignInfoException('Check-in info without a readable sign state')->setHoyolabBody($body);
        }

        return $signInfo;
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
     * The captcha (GeeTest) flags of a sign answer, when it asks for one. Such an answer keeps retcode 0 and message
     * "OK", but nothing was claimed. The global endpoints nest the flags in data.gt_result (risk_code 5001/5003,
     * is_risk true); the older shape keeps them in data (risk_code 375, no is_risk). A claimed reward has risk_code 0
     * and is_risk false, or no flags at all.
     *
     * @param array<string, mixed> $body the whole HoYoLAB answer
     * @return array<string, mixed>|null
     */
    private static function findSignRisk(array $body): ?array
    {
        $data = $body['data'] ?? null;
        if (!is_array($data)) {
            return null;
        }

        $flags = is_array($data['gt_result'] ?? null) ? $data['gt_result'] : $data;
        $riskCode = $flags['risk_code'] ?? null;

        // Not keyed on "success": it is 1 when a challenge was issued
        if (($flags['is_risk'] ?? null) === true || !in_array($riskCode, [null, 0, '0'], true)) {
            return $flags;
        }

        return null;
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
