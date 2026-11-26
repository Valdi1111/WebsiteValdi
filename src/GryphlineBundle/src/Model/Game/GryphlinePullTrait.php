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
use App\GryphlineBundle\Service\Catalog\AkeCatalog;
use Symfony\Component\HttpFoundation\Request;

/**
 * Headhunting records of Arknights: Endfield, read like the official record page (ef-webview.gryphline.com).
 *
 * Operators: one request per pool type (Chartered, RE-Factor, Special, Basic, New Horizons); the Special ("joint")
 * banners also need their pool id, listed by the record meta endpoint. Weapons: one request without filter returns
 * every weapon banner (a pool_type would be ignored), and each record is split between Arsenal Issue and RE-Factor
 * Issue by its own poolType or by the AkeData catalog.
 *
 * @mixin GameInterface
 * @mixin HasAutoPullImportInterface
 */
trait GryphlinePullTrait
{
    public const string POOL_CHARTERED = 'E_CharacterGachaPoolType_Special';
    public const string POOL_RERUN = 'E_CharacterGachaPoolType_Rerun';
    public const string POOL_JOINT = 'E_CharacterGachaPoolType_Joint';
    public const string POOL_STANDARD = 'E_CharacterGachaPoolType_Standard';
    public const string POOL_BEGINNER = 'E_CharacterGachaPoolType_Beginner';
    /** Our own keys: the weapon records have no pool type enum (Arsenal Issue keeps its historical key) */
    public const string POOL_WEAPON = 'E_WeaponGachaPoolType_Standard';
    public const string POOL_WEAPON_RERUN = 'E_WeaponGachaPoolType_Rerun';

    /** Banners whose pity resets with each banner (pool id), instead of carrying over to the next one */
    // Literal values: a trait constant cannot read the other constants of the trait
    public const array POOL_PITY_BANNERS = ['E_CharacterGachaPoolType_Joint', 'E_WeaponGachaPoolType_Standard', 'E_WeaponGachaPoolType_Rerun'];

    private const string CHAR_RECORD_ENDPOINT_URL = 'https://ef-webview.gryphline.com/api/record/char';
    private const string CHAR_META_ENDPOINT_URL = 'https://ef-webview.gryphline.com/api/record/char/meta';
    private const string WEAPON_RECORD_ENDPOINT_URL = 'https://ef-webview.gryphline.com/api/record/weapon';
    private const string USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    /** Record kinds handled by the official page; any other kind is still stored, with a warning */
    private const array KNOWN_KINDS = [GryphlinePull::KIND_DRAW, 'gift_intel_book', 'gift_operator_token', 'gift_weapon_box', 'gift_weapon', 'gift'];

    /** The weapon records mark a rerun banner with this poolType ("normal" otherwise) */
    private const string WEAPON_RECORD_RERUN_POOL_TYPE = 'rerun';

    public function getGachaConfig(): GachaConfig
    {
        return new GachaConfig(
            self::CHAR_RECORD_ENDPOINT_URL,
            [
                new GachaBannerConfig(self::POOL_CHARTERED, 'Chartered Headhunting', [self::POOL_CHARTERED], 80, 10),
                new GachaBannerConfig(self::POOL_RERUN, 'RE-Factor Headhunting', [self::POOL_RERUN], 80, 10),
                new GachaBannerConfig(self::POOL_JOINT, 'Special Headhunting', [self::POOL_JOINT], 80, 10),
                new GachaBannerConfig(self::POOL_STANDARD, 'Basic Headhunting', [self::POOL_STANDARD], 80, 10),
                new GachaBannerConfig(self::POOL_WEAPON, 'Arsenal Issue', [self::POOL_WEAPON], 40, 10, self::WEAPON_RECORD_ENDPOINT_URL),
                new GachaBannerConfig(self::POOL_WEAPON_RERUN, 'RE-Factor Issue', [self::POOL_WEAPON_RERUN], 40, 10, self::WEAPON_RECORD_ENDPOINT_URL),
                new GachaBannerConfig(self::POOL_BEGINNER, 'Beginner Headhunting', [self::POOL_BEGINNER], 50, 10),
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

    public function getSyncStreams(): array
    {
        return [
            self::POOL_CHARTERED => 'Chartered Headhunting',
            self::POOL_RERUN => 'RE-Factor Headhunting',
            self::POOL_JOINT => 'Special Headhunting',
            self::POOL_STANDARD => 'Basic Headhunting',
            // One request returns every weapon banner, split afterwards between the two weapon groups
            self::POOL_WEAPON => 'Arsenal Issue / RE-Factor Issue',
            self::POOL_BEGINNER => 'Beginner Headhunting',
        ];
    }

    public function getSyncStreamBanners(string $stream): array
    {
        $poolType = $this->getPoolTypeForCategory($stream);

        return $poolType === self::POOL_WEAPON ? [self::POOL_WEAPON, self::POOL_WEAPON_RERUN] : [$poolType];
    }

    public function isPoolPityBanner(string $bannerKey): bool
    {
        return \in_array($bannerKey, self::POOL_PITY_BANNERS, true);
    }

    public function requiresPoolId(string $stream): bool
    {
        return $this->getPoolTypeForCategory($stream) === self::POOL_JOINT;
    }

    /**
     * Pool type of a sync category: a banner key, or the legacy "char" / "weapon" aliases.
     */
    public function getPoolTypeForCategory(string $category): string
    {
        return match ($category) {
            'char' => self::POOL_CHARTERED,
            'weapon' => self::POOL_WEAPON,
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

    public function fetchStreamPoolIds(GryphlineGameProfile $profile, string $stream, string $u8Token): array
    {
        if (!$this->requiresPoolId($stream)) {
            return [];
        }

        $data = $this->requestRecords(self::CHAR_META_ENDPOINT_URL, [
            'lang' => 'en-us',
            'token' => $u8Token,
            'server_id' => $profile->getServerId() ?? '3',
        ], 'meta');
        if ($data === null) {
            return [];
        }

        $poolType = $this->getPoolTypeForCategory($stream);
        $poolIds = [];
        foreach ($data['data']['tabs'] ?? [] as $tab) {
            $poolId = \is_array($tab) ? (string) ($tab['poolId'] ?? '') : '';
            if ($poolId !== '' && ($tab['poolType'] ?? null) === $poolType) {
                $poolIds[$poolId] = $poolId;
            }
        }

        return array_values($poolIds);
    }

    /**
     * @return array<GryphlinePull>
     */
    public function fetchPullsPage(
        GryphlineGameProfile $profile,
        string $category,
        string $u8Token,
        ?string $seqId = null,
        ?string &$nextSeqId = null,
        ?string $poolId = null,
    ): array {
        $nextSeqId = null;
        $poolType = $this->getPoolTypeForCategory($category);
        $endpointType = $this->getPoolCategoryForPoolType($poolType);

        $query = [
            'server_id' => $profile->getServerId() ?? '3',
            'lang' => 'en-us',
            'token' => $u8Token,
        ];
        // The weapon endpoint takes no pool type: without a pool id it returns every weapon banner
        if ($endpointType === 'char') {
            $query['pool_type'] = $poolType;
            if ($poolId !== null && $poolId !== '') {
                $query['pool_id'] = $poolId;
            }
        }
        if ($seqId !== null && $seqId !== '') {
            $query['seq_id'] = $seqId;
        }

        $data = $this->requestRecords($this->getGachaConfig()->getEndpointUrlForBanner($poolType), $query, $poolType, $profile);
        $rawList = $data['data']['list'] ?? [];
        if ($data === null || !\is_array($rawList) || $rawList === []) {
            return [];
        }

        $hasMore = (bool) ($data['data']['hasMore'] ?? (count($rawList) >= 5));
        $lastRawItem = end($rawList);
        if ($hasMore && is_array($lastRawItem) && isset($lastRawItem['seqId'])) {
            $nextSeqId = (string) $lastRawItem['seqId'];
        }

        // Every record is kept: pulls ("draw") and bonus entries (e.g. the Headhunting Dossier), which never count.
        // A record needs a seq id (sync cursor) and a gachaTs (its date); the official page never shows one without.
        $records = array_values(array_filter(
            $rawList,
            static fn (mixed $row): bool => is_array($row)
                && isset($row['seqId']) && (string) $row['seqId'] !== ''
                && isset($row['gachaTs']) && is_numeric($row['gachaTs'])
        ));
        if ($records === []) {
            return [];
        }

        /** @var array<GryphlinePullItem> $pullItems */
        $pullItems = $this->getDenormalizer()->denormalize($records, GryphlinePullItem::class . '[]');

        $pulls = [];
        foreach ($pullItems as $index => $item) {
            $raw = $records[$index];
            // The name is required: a record without one gets the label of its kind before the mapping
            if ((string) $item->getItemName() === '') {
                $item->setItemName($item->getKind() === GryphlinePull::KIND_DRAW ? 'Unknown item' : GryphlinePull::getBonusLabel($item->getKind()));
            }

            /** @var GryphlinePull $pull */
            $pull = $this->getObjectMapper()->map($item, GryphlinePull::class);
            $pull->setGameProfile($profile);
            $pull->setPoolType($endpointType === 'weapon' ? $this->resolveWeaponPoolType($raw, $pull) : $poolType);
            $pull->setPoolCategory($endpointType);
            $pull->setRawPayload($raw);
            if (!\in_array($pull->getKind(), self::KNOWN_KINDS, true)) {
                $this->getLogger()->warning(sprintf(
                    'Endfield record %s of %s has an unknown kind "%s": stored as a bonus entry.',
                    $pull->getSeqId(),
                    $profile->getNickname(),
                    $pull->getKind()
                ));
            }
            $pulls[] = $pull;
        }

        return $pulls;
    }

    /**
     * Weapon group of a weapon record: RE-Factor Issue when the record (as the official page reads it) or the
     * AkeData catalog says its banner is a rerun, Arsenal Issue otherwise (permanent banners included).
     *
     * @param array<string, mixed> $raw
     */
    private function resolveWeaponPoolType(array $raw, GryphlinePull $pull): string
    {
        $recordPoolType = isset($raw['poolType']) ? (string) $raw['poolType'] : null;
        $recordSaysRerun = $recordPoolType === self::WEAPON_RECORD_RERUN_POOL_TYPE;

        $catalogPool = $this->getCatalog()?->findPool((string) $pull->getPoolId());
        $catalogSaysRerun = $catalogPool !== null && $catalogPool['category'] === AkeCatalog::TYPE_WEAPON
            ? $catalogPool['type'] === AkeCatalog::WEAPON_POOL_TYPE_RERUN
            : null;

        if ($recordPoolType !== null && $catalogSaysRerun !== null && $catalogSaysRerun !== $recordSaysRerun) {
            $this->getLogger()->warning(sprintf(
                'Endfield weapon banner %s: the record says poolType "%s", the catalog says %s.',
                (string) $pull->getPoolId(),
                $recordPoolType,
                $catalogSaysRerun ? 'rerun' : 'normal'
            ));
        }

        return $recordSaysRerun || $catalogSaysRerun === true ? self::POOL_WEAPON_RERUN : self::POOL_WEAPON;
    }

    /**
     * One request to the record API. Returns null when a pool has no history window (code 50000).
     *
     * @param array<string, string> $query
     * @return array<string, mixed>|null
     */
    private function requestRecords(string $url, array $query, string $label, ?GryphlineGameProfile $profile = null): ?array
    {
        try {
            $response = $this->getHttpClient()->request(Request::METHOD_GET, $url, [
                'query' => $query,
                'headers' => [
                    'Accept' => 'application/json',
                    'User-Agent' => self::USER_AGENT,
                ],
            ]);

            $data = $response->toArray(false);
        } catch (\Throwable $e) {
            throw new SkportApiException(sprintf('Failed to fetch Endfield records for %s: %s', $label, $e->getMessage()), 0, $e);
        }

        $code = (int) ($data['code'] ?? -1);
        if ($code === 50000) {
            // Gryphline returns 50000 ("save seq id unavailable") when a pool currently has no active/cached history window.
            $this->getLogger()->info(sprintf(
                'Endfield %s returned code 50000 (%s) for %s; treating as empty.',
                $label,
                $data['msg'] ?? $data['message'] ?? 'save seq id unavailable',
                $profile?->getNickname() ?? 'the profile'
            ));

            return null;
        }

        if ($code !== 0) {
            throw new SkportApiException(sprintf(
                'Endfield gacha API error for %s: %s (code: %d)',
                $label,
                $data['msg'] ?? $data['message'] ?? 'Unknown error',
                $code
            ));
        }

        return $data;
    }
}
