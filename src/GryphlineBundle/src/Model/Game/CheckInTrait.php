<?php

namespace App\GryphlineBundle\Model\Game;

use App\GamesBundle\Model\Game\CachedProfileData;
use App\GamesBundle\Model\Game\CheckInStatus;
use App\GamesBundle\Model\Game\HasCheckInStatusInterface;
use App\GamesBundle\Model\Game\RuntimeAccountDataInterface;
use App\GryphlineBundle\Exception\SkportApiException;
use App\GamesBundle\Model\Game\AwardData;
use App\GamesBundle\Model\Game\CheckInResult;
use App\GryphlineBundle\Model\RuntimeAccountData;
use App\GryphlineBundle\Model\SkportSessionCredentials;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * The check-in status reads the fetch time with the notes clock ($notesClock of CachedNotesTrait, through NotesTrait),
 * so the class using this trait uses NotesTrait too.
 *
 * @mixin GameInterface
 * @mixin HasCheckInInterface
 * @mixin HasCheckInStatusInterface
 */
trait CheckInTrait
{
    private ?CacheInterface $checkInStatusCache = null;

    public function getCheckInStatusCache(): ?CacheInterface
    {
        return $this->checkInStatusCache;
    }

    #[Required]
    public function setCheckInStatusCache(
        #[Target('gryphline.check_in_status.cache')]
        CacheInterface $cache
    ): void
    {
        $this->checkInStatusCache = $cache;
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
            $this->notesClock
        );
        $data = $entry['data'];

        return new CheckInStatus(
            // Same reading as checkIn(): the flag of today, and the done records of the calendar
            signedToday: (bool) ($data['hasToday'] ?? false),
            totalDays: count(self::getDoneAttendanceRecords($data)),
            fetchedAt: CachedProfileData::fetchedAt($entry, $this->notesClock),
        );
    }

    public function checkIn(RuntimeAccountData $account, bool $retryOn401 = true): CheckInResult
    {
        $profile = $account->getGameProfile();
        $creds = $this->ensureCredentials($account);

        $this->getLogger()->info(sprintf(
            'Starting check-in procedure for %s (UID: %s, SkGameRole: %s)',
            $profile->getNickname() ?? 'Unknown',
            $profile->getGameUid(),
            $profile->getSkGameRole()
        ));

        try {
            // 1. Check today attendance status
            $attendanceData = $this->getAttendanceData($account, $creds);
        } catch (SkportApiException $e) {
            if ($e->getApiStatusCode() === Response::HTTP_UNAUTHORIZED && $retryOn401) {
                $this->getLogger()->warning('SKPort session expired during check-in, forcing OAuth refresh...');
                $this->ensureCredentials($account, force: true);
                return $this->checkIn($account, retryOn401: false);
            }
            throw $e;
        }

        $hasToday = (bool) ($attendanceData['hasToday'] ?? false);
        $resourceMap = $attendanceData['resourceInfoMap'] ?? [];

        $doneRecords = self::getDoneAttendanceRecords($attendanceData);
        $totalSignDay = count($doneRecords);

        if ($hasToday) {
            $this->forgetCheckInStatus($account);
            $this->getLogger()->info(sprintf(
                'Account %s has already signed in today (Total: %d days).',
                $profile->getNickname() ?? $profile->getGameUid(),
                $totalSignDay
            ));

            $lastAward = null;
            if ($totalSignDay > 0) {
                $lastRecord = $doneRecords[$totalSignDay - 1];
                $awardId = $lastRecord['awardId'] ?? null;
                if ($awardId && isset($resourceMap[$awardId])) {
                    $item = $resourceMap[$awardId];
                    $lastAward = new AwardData()
                        ->setId((string) $awardId)
                        ->setName((string) ($item['name'] ?? 'Reward'))
                        ->setCount((int) ($item['count'] ?? 1))
                        ->setIcon($item['icon'] ?? null);
                }
            }

            return new CheckInResult()
                ->setStatus('already_claimed')
                ->setAlreadySignedIn(true)
                ->setTotalSignDay($totalSignDay)
                ->setResult('You have already checked in today!')
                ->setAwardData($lastAward);
        }

        // 2. Perform attendance claim (POST signed with V2)
        try {
            $claimData = $this->claimAttendance($account, $creds);
        } catch (SkportApiException $e) {
            if ($e->getApiStatusCode() === Response::HTTP_UNAUTHORIZED && $retryOn401) {
                $this->getLogger()->warning('SKPort session expired during claim attendance, forcing OAuth refresh...');
                $this->ensureCredentials($account, force: true);
                return $this->checkIn($account, retryOn401: false);
            }
            throw $e;
        }

        // Right after the claim, before anything else can fail: the status must not keep showing "not signed"
        $this->forgetCheckInStatus($account);
        $totalSignDay++;

        $awardIds = $claimData['awardIds'] ?? [];
        $claimResourceMap = $claimData['resourceInfoMap'] ?? $resourceMap;
        $claimedAward = null;

        if (!empty($awardIds)) {
            $firstAward = $awardIds[0];
            $awardId = is_array($firstAward) ? ($firstAward['id'] ?? null) : $firstAward;
            if ($awardId && isset($claimResourceMap[$awardId])) {
                $item = $claimResourceMap[$awardId];
                $claimedAward = new AwardData()
                    ->setId((string) $awardId)
                    ->setName((string) ($item['name'] ?? 'Reward'))
                    ->setCount((int) ($item['count'] ?? 1))
                    ->setIcon($item['icon'] ?? null);
            }
        }

        $this->getLogger()->info(sprintf(
            'Check-in completed successfully for %s! Reward: %s x%d (Total: %d days).',
            $profile->getNickname() ?? $profile->getGameUid(),
            $claimedAward?->getName() ?? 'Item',
            $claimedAward?->getCount() ?? 1,
            $totalSignDay
        ));

        return new CheckInResult()
            ->setStatus('claimed')
            ->setAlreadySignedIn(false)
            ->setTotalSignDay($totalSignDay)
            ->setResult('Daily check-in completed successfully!')
            ->setAwardData($claimedAward);
    }

    private function getAttendanceData(RuntimeAccountData $account, SkportSessionCredentials $creds): array
    {
        $profile = $account->getGameProfile();
        $path = '/web/v1/game/endfield/attendance';
        $timestamp = (string) time();
        $sign = $this->getSignerService()->generateSignV1($timestamp, $creds->getCred());

        $headers = [
            'cred' => $creds->getCred(),
            'timestamp' => $timestamp,
            'platform' => '3',
            'vname' => '1.0.0',
            'sk-language' => 'en',
            'sk-game-role' => $profile->getSkGameRole(),
            'sign' => $sign,
        ];

        $response = $this->getSkportClient()->request(Request::METHOD_GET, $path, [
            'headers' => $headers,
        ]);

        if ($response->getStatusCode() !== Response::HTTP_OK) {
            throw new SkportApiException('Attendance check HTTP error: ' . $response->getStatusCode())
                ->setApiStatusCode($response->getStatusCode());
        }

        $body = $response->toArray(false);
        if (($body['code'] ?? -1) !== 0) {
            throw new SkportApiException('Attendance check failed: ' . ($body['message'] ?? 'Error'))
                ->setApiCode($body['code'] ?? null)
                ->setResponseBody($body);
        }

        return $body['data'] ?? [];
    }

    private function claimAttendance(RuntimeAccountData $account, SkportSessionCredentials $creds): array
    {
        $profile = $account->getGameProfile();
        $path = '/web/v1/game/endfield/attendance';
        $timestamp = (string) time();
        $sign = $this->getSignerService()->generateSignV2($path, $timestamp, $creds->getSalt());

        $headers = [
            'cred' => $creds->getCred(),
            'timestamp' => $timestamp,
            'platform' => '3',
            'vname' => '1.0.0',
            'sk-game-role' => $profile->getSkGameRole(),
            'sign' => $sign,
        ];

        $response = $this->getSkportClient()->request(Request::METHOD_POST, $path, [
            'headers' => $headers,
        ]);

        if ($response->getStatusCode() !== Response::HTTP_OK) {
            throw new SkportApiException('Claim attendance HTTP error: ' . $response->getStatusCode())
                ->setApiStatusCode($response->getStatusCode());
        }

        $body = $response->toArray(false);
        if (($body['code'] ?? -1) !== 0) {
            throw new SkportApiException('Claim attendance failed: ' . ($body['message'] ?? 'Error'))
                ->setApiCode($body['code'] ?? null)
                ->setResponseBody($body);
        }

        return $body['data'] ?? [];
    }

    /**
     * Read-only attendance request (GET, never the claim), with one forced OAuth refresh on an expired session.
     * The failed attempt throws inside the fetch, so only the answer of the retry can reach the cache.
     *
     * @return array{0: array<string, mixed>, 1: bool} the attendance payload, and whether it may be cached
     */
    private function fetchCheckInStatusData(RuntimeAccountData $account, bool $retryOn401 = true): array
    {
        try {
            $data = $this->getAttendanceData($account, $this->ensureCredentials($account));
        } catch (SkportApiException $e) {
            if ($e->getApiStatusCode() === Response::HTTP_UNAUTHORIZED && $retryOn401) {
                $this->getLogger()->warning('SKPort session expired during check-in status request, forcing OAuth refresh...');
                $this->ensureCredentials($account, force: true);

                return $this->fetchCheckInStatusData($account, retryOn401: false);
            }
            throw $e;
        }

        // getAttendanceData() answers [] when the payload has no data: used as not signed, never cached
        return [$data, $data !== []];
    }

    /**
     * Claimed days of the attendance calendar, in calendar order.
     *
     * @param array<string, mixed> $attendanceData
     * @return list<array<string, mixed>>
     */
    private static function getDoneAttendanceRecords(array $attendanceData): array
    {
        $calendar = $attendanceData['calendar'] ?? [];
        if (!is_array($calendar)) {
            return [];
        }

        return array_values(array_filter($calendar, static fn(mixed $c): bool => is_array($c) && !empty($c['done'])));
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
