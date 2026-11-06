<?php

namespace App\GryphlineBundle\Model\Game;

use App\GryphlineBundle\Exception\SkportApiException;
use App\CoreBundle\Model\Game\AwardData;
use App\CoreBundle\Model\Game\CheckInResult;
use App\GryphlineBundle\Model\RuntimeAccountData;
use App\GryphlineBundle\Model\SkportSessionCredentials;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @mixin GameInterface
 * @mixin HasCheckInInterface
 */
trait CheckInTrait
{
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
        $calendar = $attendanceData['calendar'] ?? [];
        $resourceMap = $attendanceData['resourceInfoMap'] ?? [];

        $doneRecords = array_values(array_filter($calendar, static fn(array $c): bool => !empty($c['done'])));
        $totalSignDay = count($doneRecords);

        if ($hasToday) {
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
}
