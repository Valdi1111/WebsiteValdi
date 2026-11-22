<?php

namespace App\HoyoverseBundle\Model\Game;

use App\CoreBundle\Doctrine\WallClockDateTimeImmutableType;
use App\GamesBundle\Model\Game\HasSRankHistoryImportInterface;
use App\GamesBundle\Model\Game\RuntimeAccountDataInterface;
use App\GamesBundle\Model\Pull\SRankHistory\SRankHistory;
use App\GamesBundle\Model\Pull\SRankHistory\SRankHistoryBanner;
use App\GamesBundle\Model\Pull\SRankHistory\SRankHistoryRecord;
use App\HoyoverseBundle\Exception\RetrieveSRankHistoryException;
use App\HoyoverseBundle\Service\Pull\HoyoverseServerTimezoneResolver;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * Official S-rank history of Zenless Zone Zero: the "Signal Search" statistics of the HoYoLAB battle record. Unlike
 * getGachaLog they have no 6-month limit, but list only the S-ranks, each with its pull number in the banner ("sort"),
 * its pity ("count"), its date ("record_time", epoch) and whether it was the rate-up item ("is_up").
 *
 * Three requests, all with the HoYoLAB cookie (ltoken_v2, ltuid_v2, ltmid_v2 are enough: no stoken_v2, no DS header):
 * - "index": one entry per banner ("type_stats") with its totals and the versions holding data ("has_data_vers"), plus
 *   the current version ("cur_ver");
 * - "hist_pool_stat": the phases of past versions, at most S_RANK_HISTORY_MAX_VERSIONS per request (more versions, or a
 *   version that does not exist, give retcode -400005, so the list always comes from "index");
 * - "cur_pool_stat": the phases of the current version.
 *
 * About 15 requests for a whole account, spaced by S_RANK_HISTORY_PAUSE_SECONDS: an on-demand import, not a sync.
 *
 * @mixin GameInterface
 * @mixin HasSRankHistoryImportInterface
 */
trait ZenlessZoneZeroSRankHistoryTrait
{
    public const string S_RANK_HISTORY_URL = 'https://sg-act-public-api.hoyolab.com/event/nap_gacha_record/api/zzz/';

    public const int S_RANK_HISTORY_MAX_VERSIONS = 10;

    /** Pause between two requests of one import (none when no clock is injected, e.g. in unit tests) */
    public const float S_RANK_HISTORY_PAUSE_SECONDS = 1.0;

    /**
     * Banner type of "index" => [gacha_type of the other requests, banner key of getGachaConfig()]. The two rerun banners
     * use 102 and 103 there (12 gives retcode -502), and 12 and 13 in getGachaLog and in the stored pulls.
     */
    public const array S_RANK_HISTORY_BANNERS = [
        'GACHA_TYPE_CHARACTER_UP' => ['2', '2'],
        'GACHA_TYPE_WEAPON_UP' => ['3', '3'],
        'GACHA_TYPE_CHARACTER_RETURN' => ['102', '12'],
        'GACHA_TYPE_WEAPON_RETURN' => ['103', '13'],
        'GACHA_TYPE_PERMANENT' => ['1', '1'],
        'GACHA_TYPE_BANGBOO' => ['5', '5'],
    ];

    /** Item types of the records => item types of the stored pulls (getGachaLog, zzz.rng.moe) */
    public const array S_RANK_HISTORY_ITEM_TYPES = [
        'ItemType_Avatar' => 'Agents',
        'ItemType_Weapon' => 'W-Engines',
        'ItemType_Bangboo' => 'Bangboo',
    ];

    private ?ClockInterface $sRankHistoryClock = null;

    #[Required]
    public function setSRankHistoryClock(?ClockInterface $sRankHistoryClock): void
    {
        $this->sRankHistoryClock = $sRankHistoryClock;
    }

    /**
     * Provided by ZenlessZoneZeroEndgamesTrait: converts the epochs of the records to the server wall clock.
     */
    abstract public function getServerTimezoneResolver(): ?HoyoverseServerTimezoneResolver;

    public function getUrlSRankHistory(string $endpoint): string
    {
        return self::S_RANK_HISTORY_URL.$endpoint;
    }

    /**
     * @throws RetrieveSRankHistoryException when a request fails or a record cannot be read
     */
    public function getSRankHistory(RuntimeAccountDataInterface $account): SRankHistory
    {
        $profile = $account->getGameProfile();
        $region = (string) $profile->getRegion();
        $timezone = ($this->getServerTimezoneResolver() ?? throw new \LogicException('The server timezone resolver is not set.'))
            ->getServerTimezoneForRegion($region);
        $baseQuery = ['uid' => (string) $profile->getGameUid(), 'region' => $region, 'lang' => 'en-us'];
        $requests = 0;

        $data = $this->requestSRankHistory('index', $baseQuery, $account, $requests);
        if (!\is_array($data['type_stats'] ?? null)) {
            throw new RetrieveSRankHistoryException('The Signal Search statistics have no banner list ("type_stats").');
        }
        // Without it the current version would be requested from hist_pool_stat (retcode -400005) or read twice
        $currentVersion = \is_scalar($data['cur_ver'] ?? null) ? trim((string) $data['cur_ver']) : '';
        if ($currentVersion === '') {
            throw new RetrieveSRankHistoryException('The Signal Search statistics have no current version ("cur_ver").');
        }

        $banners = [];
        $warnings = [];
        $seenTypes = [];
        foreach ($data['type_stats'] as $stats) {
            $type = \is_array($stats) ? (string) ($stats['gacha_type'] ?? '') : '';
            if (!isset(self::S_RANK_HISTORY_BANNERS[$type])) {
                $warnings[] = \sprintf('Unknown banner type "%s" in the Signal Search statistics: ignored.', $type);
                continue;
            }
            [$requestType, $bannerKey] = self::S_RANK_HISTORY_BANNERS[$type];
            // Two entries of one banner cannot both be right: nothing is guessed, nothing is imported
            if (isset($seenTypes[$type])) {
                throw new RetrieveSRankHistoryException(\sprintf('Banner type "%s" is listed twice in the Signal Search statistics.', $type));
            }
            $seenTypes[$type] = true;

            $versions = array_values(array_unique(array_map(
                'strval',
                array_filter(\is_array($stats['has_data_vers'] ?? null) ? $stats['has_data_vers'] : [], 'is_scalar'),
            )));
            $totalPulls = self::readSRankHistoryInt($stats['total_count'] ?? null);

            $records = [];
            if ($versions !== [] || ($totalPulls ?? 0) > 0) {
                $pastVersions = array_values(array_filter($versions, static fn (string $version): bool => $version !== $currentVersion));
                foreach (array_chunk($pastVersions, self::S_RANK_HISTORY_MAX_VERSIONS) as $chunk) {
                    $phases = $this->requestSRankHistory('hist_pool_stat', $baseQuery + ['gacha_type' => $requestType, 'vers' => implode(',', $chunk)], $account, $requests);
                    array_push($records, ...$this->readSRankHistoryPhases($phases, $timezone, $warnings));
                }
                $phases = $this->requestSRankHistory('cur_pool_stat', $baseQuery + ['gacha_type' => $requestType], $account, $requests);
                array_push($records, ...$this->readSRankHistoryPhases($phases, $timezone, $warnings));
            }

            $banners[] = new SRankHistoryBanner(
                bannerKey: $bannerKey,
                records: $records,
                totalPulls: $totalPulls,
                sCount: self::readSRankHistoryInt($stats['s_count'] ?? null),
                currentPity: self::readSRankHistoryInt($stats['next_s_cur_times'] ?? null),
            );
        }

        return new SRankHistory($banners, $warnings);
    }

    /**
     * @param array<string, string> $query
     *
     * @return array<string, mixed> The "data" of the response
     */
    private function requestSRankHistory(string $endpoint, array $query, RuntimeAccountDataInterface $account, int &$requests): array
    {
        if ($requests > 0) {
            $this->sRankHistoryClock?->sleep(self::S_RANK_HISTORY_PAUSE_SECONDS);
        }
        ++$requests;

        $body = $this->requestHoyolab(
            method: Request::METHOD_GET,
            url: $this->getUrlSRankHistory($endpoint),
            auth: $account,
            query: $query,
            exceptionClass: RetrieveSRankHistoryException::class,
        );

        return \is_array($body['data'] ?? null) ? $body['data'] : [];
    }

    /**
     * Records of the phases ("list") of a hist_pool_stat or cur_pool_stat response, whatever their state (ended, in
     * progress; a phase not started yet has none).
     *
     * @param array<string, mixed> $data
     * @param list<string>         $warnings
     *
     * @return list<SRankHistoryRecord>
     *
     * @throws RetrieveSRankHistoryException for a record without pull number, pity, date or name
     */
    private function readSRankHistoryPhases(array $data, \DateTimeZone $timezone, array &$warnings): array
    {
        $records = [];
        foreach (\is_array($data['list'] ?? null) ? $data['list'] : [] as $phase) {
            foreach (\is_array($phase['cur_records'] ?? null) ? $phase['cur_records'] : [] as $record) {
                if (!\is_array($record)) {
                    continue;
                }
                $name = trim((string) ($record['name'] ?? ''));
                if (strtoupper((string) ($record['rarity'] ?? '')) !== 'S') {
                    $warnings[] = \sprintf('Record "%s" of rarity "%s" ignored: only S-ranks are expected.', $name, (string) ($record['rarity'] ?? ''));
                    continue;
                }

                $pullNumber = self::readSRankHistoryInt($record['sort'] ?? null);
                $pity = self::readSRankHistoryInt($record['count'] ?? null);
                $epoch = self::readSRankHistoryInt($record['record_time'] ?? null);
                if ($pullNumber === null || $pity === null || $epoch === null || $name === '') {
                    throw new RetrieveSRankHistoryException(\sprintf('Unreadable record "%s" in the Signal Search statistics.', $name));
                }

                $itemId = trim((string) ($record['item_id'] ?? ''));
                $itemType = (string) ($record['item_type'] ?? '');
                $records[] = new SRankHistoryRecord(
                    itemId: $itemId !== '' ? $itemId : null,
                    name: $name,
                    itemType: self::S_RANK_HISTORY_ITEM_TYPES[$itemType] ?? mb_substr($itemType !== '' ? $itemType : 'Unknown', 0, 32),
                    pullNumber: $pullNumber,
                    pity: $pity,
                    pulledAt: WallClockDateTimeImmutableType::fromDateTime((new \DateTimeImmutable('@'.$epoch))->setTimezone($timezone)),
                    isUp: \is_bool($record['is_up'] ?? null) ? $record['is_up'] : null,
                );
            }
        }

        return $records;
    }

    /**
     * The numbers of these responses are strings ("2733"), sometimes integers.
     */
    private static function readSRankHistoryInt(mixed $value): ?int
    {
        if (\is_int($value)) {
            return $value;
        }

        return \is_string($value) && ctype_digit($value) ? (int) $value : null;
    }
}
