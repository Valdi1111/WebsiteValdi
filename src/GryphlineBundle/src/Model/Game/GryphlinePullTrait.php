<?php

namespace App\GryphlineBundle\Model\Game;

use App\GamesBundle\Model\Game\GachaBannerConfig;
use App\GamesBundle\Model\Game\GachaConfig;
use App\GamesBundle\Model\Game\RarityConfig;
use App\GamesBundle\Model\Game\RarityTierConfig;
use App\GryphlineBundle\Entity\GryphlineGameProfile;
use App\GryphlineBundle\Entity\GryphlinePull;
use App\GryphlineBundle\Exception\SkportApiException;
use App\GryphlineBundle\Model\Pull\GryphlinePullItem;
use Symfony\Component\HttpFoundation\Request;

/**
 * @mixin GameInterface
 * @mixin HasAutoPullImportInterface
 */
trait GryphlinePullTrait
{
    private const string CHAR_RECORD_ENDPOINT_URL = 'https://ef-webview.gryphline.com/api/record/char';
    private const string WEAPON_RECORD_ENDPOINT_URL = 'https://ef-webview.gryphline.com/api/record/weapon';

    public function getGachaConfig(): GachaConfig
    {
        return new GachaConfig(
            self::CHAR_RECORD_ENDPOINT_URL,
            [
                new GachaBannerConfig('E_CharacterGachaPoolType_Special', 'Chartered Headhunting', ['E_CharacterGachaPoolType_Special'], 80, 10),
                new GachaBannerConfig('E_CharacterGachaPoolType_Standard', 'Basic Headhunting', ['E_CharacterGachaPoolType_Standard'], 80, 10),
                new GachaBannerConfig('E_WeaponGachaPoolType_Standard', 'Weapon Headhunting', ['E_WeaponGachaPoolType_Standard'], 40, 10, self::WEAPON_RECORD_ENDPOINT_URL),
                new GachaBannerConfig('E_CharacterGachaPoolType_Beginner', 'Beginner Headhunting', ['E_CharacterGachaPoolType_Beginner'], 50, 10),
            ]
        );
    }

    public function getRarityConfig(): RarityConfig
    {
        return new RarityConfig(
            s: new RarityTierConfig(6, '6-Star', '6★'),
            a: new RarityTierConfig(5, '5-Star', '5★'),
            b: new RarityTierConfig(4, '4-Star', '4★'),
            pullLabel: 'Headhunts',
            pullSingularLabel: 'Headhunt',
            sResetsAPity: true,
        );
    }

    /**
     * Pool type of a sync category: a banner key, or the legacy "char" / "weapon" aliases.
     */
    public function getPoolTypeForCategory(string $category): string
    {
        return match ($category) {
            'char' => 'E_CharacterGachaPoolType_Special',
            'weapon' => 'E_WeaponGachaPoolType_Standard',
            default => $category,
        };
    }

    /**
     * Pool category ("char" or "weapon", the record endpoint) of a pool type.
     */
    public function getPoolCategoryForPoolType(string $poolType): string
    {
        return str_starts_with($poolType, 'E_Weapon') || $poolType === 'weapon' ? 'weapon' : 'char';
    }

    /**
     * @return array<GryphlinePull>
     */
    public function fetchPullsPage(
        GryphlineGameProfile $profile,
        string $category,
        string $u8Token,
        ?string $seqId = null,
        ?string &$nextSeqId = null
    ): array {
        $nextSeqId = null;
        $serverId = $profile->getServerId() ?? '3';
        $endpointType = $this->getPoolCategoryForPoolType($category);
        $poolType = $this->getPoolTypeForCategory($category);

        $query = [
            'server_id' => $serverId,
            'pool_type' => $poolType,
            'lang' => 'en-us',
            'token' => $u8Token,
        ];
        if ($seqId !== null && $seqId !== '') {
            $query['seq_id'] = $seqId;
        }

        $url = $this->getGachaConfig()->getEndpointUrlForBanner($poolType);

        try {
            $response = $this->getHttpClient()->request(Request::METHOD_GET, $url, [
                'query' => $query,
                'headers' => [
                    'Accept' => 'application/json',
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                ],
            ]);

            $data = $response->toArray(false);
        } catch (\Throwable $e) {
            throw new SkportApiException(sprintf('Failed to fetch Endfield pulls for pool %s: %s', $poolType, $e->getMessage()), 0, $e);
        }

        $code = (int) ($data['code'] ?? -1);
        if ($code === 50000) {
            // Gryphline returns 50000 ("save seq id unavailable") when a pool currently has no active/cached history window.
            $this->getLogger()->info(sprintf(
                'Endfield pool %s returned code 50000 (%s) for %s; treating as empty.',
                $poolType,
                $data['msg'] ?? $data['message'] ?? 'save seq id unavailable',
                $profile->getNickname()
            ));

            return [];
        }

        if ($code !== 0) {
            throw new SkportApiException(sprintf(
                'Endfield gacha API error for pool %s: %s (code: %d)',
                $poolType,
                $data['msg'] ?? $data['message'] ?? 'Unknown error',
                $code
            ));
        }

        $rawList = $data['data']['list'] ?? [];
        if (empty($rawList)) {
            return [];
        }

        $hasMore = (bool) ($data['data']['hasMore'] ?? (count($rawList) >= 5));
        $lastRawItem = end($rawList);
        if ($hasMore && is_array($lastRawItem) && isset($lastRawItem['seqId'])) {
            $nextSeqId = (string) $lastRawItem['seqId'];
        }

        // Filter out non-pull bonus log entries (e.g. kind = "gift_intel_book" Headhunting Dossier)
        $drawRawList = array_values(array_filter(
            $rawList,
            static fn (mixed $row): bool => is_array($row)
                && (($row['kind'] ?? 'draw') === 'draw')
                && (!empty($row['charId']) || !empty($row['weaponId']) || !empty($row['itemId']))
        ));

        if (empty($drawRawList)) {
            return [];
        }

        /** @var array<GryphlinePullItem> $pullItems */
        $pullItems = $this->getDenormalizer()->denormalize(
            $drawRawList,
            GryphlinePullItem::class . '[]'
        );

        $pulls = [];
        foreach ($pullItems as $index => $item) {
            /** @var GryphlinePull $pull */
            $pull = $this->getObjectMapper()->map($item, GryphlinePull::class);
            $pull->setGameProfile($profile);
            $pull->setPoolType($poolType);
            $pull->setPoolCategory($endpointType);
            $pull->setRawPayload($drawRawList[$index] ?? null);
            $pulls[] = $pull;
        }

        return $pulls;
    }
}
