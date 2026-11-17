<?php

namespace App\GryphlineBundle\Model\Game;

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
    /**
     * @return array<string>
     */
    public function getPullPoolCategories(): array
    {
        return [
            'E_CharacterGachaPoolType_Special',
            'E_CharacterGachaPoolType_Standard',
            'E_CharacterGachaPoolType_Beginner',
            'E_WeaponGachaPoolType_Standard',
        ];
    }

    public function getGachaBannerGroups(): array
    {
        return [
            'E_CharacterGachaPoolType_Special' => [
                'key' => 'E_CharacterGachaPoolType_Special',
                'name' => 'Chartered Headhunting',
                'categories' => ['E_CharacterGachaPoolType_Special'],
                's_max_pity' => 80,
                'a_max_pity' => 10,
            ],
            'E_CharacterGachaPoolType_Standard' => [
                'key' => 'E_CharacterGachaPoolType_Standard',
                'name' => 'Basic Headhunting',
                'categories' => ['E_CharacterGachaPoolType_Standard'],
                's_max_pity' => 80,
                'a_max_pity' => 10,
            ],
            'E_WeaponGachaPoolType_Standard' => [
                'key' => 'E_WeaponGachaPoolType_Standard',
                'name' => 'Weapon Headhunting',
                'categories' => ['E_WeaponGachaPoolType_Standard'],
                's_max_pity' => 80,
                'a_max_pity' => 10,
            ],
            'E_CharacterGachaPoolType_Beginner' => [
                'key' => 'E_CharacterGachaPoolType_Beginner',
                'name' => 'Beginner Headhunting',
                'categories' => ['E_CharacterGachaPoolType_Beginner'],
                's_max_pity' => 50,
                'a_max_pity' => 10,
            ],
        ];
    }

    public function getRarityConfig(): array
    {
        return [
            's_rarity' => 6,
            's_label' => '6★',
            's_short' => '6★',
            'a_rarity' => 5,
            'a_label' => '5★',
            'a_short' => '5★',
            'b_rarity' => 4,
            'b_label' => '4★',
            'b_short' => '4★',
            'pull_label' => 'Headhunts',
        ];
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
        $isWeapon = str_starts_with($category, 'E_Weapon') || $category === 'weapon';
        $endpointType = $isWeapon ? 'weapon' : 'char';
        $poolType = match ($category) {
            'char' => 'E_CharacterGachaPoolType_Special',
            'weapon' => 'E_WeaponGachaPoolType_Standard',
            default => $category,
        };

        $query = [
            'server_id' => $serverId,
            'pool_type' => $poolType,
            'lang' => 'en-us',
            'token' => $u8Token,
        ];
        if ($seqId !== null && $seqId !== '') {
            $query['seq_id'] = $seqId;
        }

        $url = sprintf('https://ef-webview.gryphline.com/api/record/%s', $endpointType);

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

        if (($data['code'] ?? -1) !== 0) {
            throw new SkportApiException(sprintf(
                'Endfield gacha API error for pool %s: %s (code: %d)',
                $poolType,
                $data['msg'] ?? $data['message'] ?? 'Unknown error',
                $data['code'] ?? -1
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
