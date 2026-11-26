<?php

namespace App\Tests\GryphlineBundle\Service\Catalog;

use App\GryphlineBundle\Service\Catalog\AkeCatalog;
use App\GryphlineBundle\Service\Catalog\AkeCatalogBuilder;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Tiny fake AkeData site served through MockHttpClient (rows and names copied from version 1.6.4@11944529-13).
 * realFiles() swaps in the real pool tables of version 1.7.2@12153128-4 (reduced to the fields the builder
 * reads) and the 6★ items they and the irminsul.gg banners feature.
 */
final class AkeDataFixture
{
    public const string BASE_URI = 'https://data.akedata.wiki';
    public const string LATEST = '1.6.4@11944529-13';
    public const string LATEST_PATH = 'public/1.6.4/11944529-13/TableCfg';
    public const string OLDER = '1.6.4@11775769-12';
    public const string OLDER_PATH = 'public/1.6.4/11775769-12/TableCfg';
    public const string CHAR_POOL_TABLE = 'GachaCharPoolTable.json';
    public const string WEAPON_POOL_TABLE = 'GachaWeaponPoolTable.json';

    /** @var list<string> paths requested on the AkeData host */
    public array $requestedPaths = [];

    /**
     * @param array<string, mixed> $files path => decoded JSON (or raw string); null = 404
     */
    public function __construct(public array $files = [])
    {
        $this->files = array_replace(self::defaultFiles(), $files);
    }

    /**
     * Builder reading this fake site and the given fake irminsul.gg (the default tiny one when null), keeping
     * the irminsul fields of $previous (an empty catalog when null) when irminsul.gg is down.
     */
    public function builder(
        ?LoggerInterface $logger = null,
        ?EndfieldIrminsulFixture $irminsul = null,
        ?AkeCatalog $previous = null,
    ): AkeCatalogBuilder {
        $irminsul ??= new EndfieldIrminsulFixture();

        return new AkeCatalogBuilder(
            $this->client(),
            $logger ?? new NullLogger(),
            $irminsul->irminsulClient(),
            EndfieldIrminsulFixture::normalizer(),
            $previous ?? new AkeCatalog(new ArrayAdapter()),
        );
    }

    public function client(): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url): MockResponse {
            $path = substr($url, strlen(self::BASE_URI . '/'));
            $this->requestedPaths[] = $path;

            $content = str_starts_with($url, self::BASE_URI . '/') ? ($this->files[$path] ?? null) : null;
            if ($content === null) {
                return new MockResponse('<html>Not Found</html>', ['http_code' => 404]);
            }

            return new MockResponse(
                is_string($content) ? $content : json_encode($content, \JSON_THROW_ON_ERROR),
                ['response_headers' => ['content-type' => 'application/json']],
            );
        }, self::BASE_URI);
    }

    /**
     * ItemTable row reduced to the fields the builder reads (plus a few real ones).
     *
     * @return array<string, mixed>
     */
    public static function itemRow(string $id, int $textId, int $rarity, int $type): array
    {
        return [
            'iconId' => $id,
            'id' => $id,
            'name' => ['id' => $textId, 'text' => ''],
            'desc' => ['id' => 98088074095664370, 'text' => ''],
            'rarity' => $rarity,
            'type' => $type,
        ];
    }

    /**
     * GachaCharPoolTable / GachaWeaponPoolTable row reduced to the fields the builder reads (plus a few
     * real UI ones).
     *
     * @param list<mixed> $up
     *
     * @return array<string, mixed>
     */
    public static function poolRow(string $id, mixed $type, string $upField, array $up, int $poolVersion = 0): array
    {
        return [
            'gachaPoolVersion' => $poolVersion,
            'id' => $id,
            'name' => ['id' => 4242424242, 'text' => ''],
            'sortId' => 10,
            'type' => $type,
            $upField => $up,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function manifest(): array
    {
        return [
            'schemaVersion' => 1,
            'latest' => self::LATEST,
            'versions' => [
                ['id' => self::LATEST, 'gameVersion' => '1.6.4', 'hotfixVersion' => '11944529-13', 'tableCfgPath' => self::LATEST_PATH],
                ['id' => self::OLDER, 'gameVersion' => '1.6.4', 'hotfixVersion' => '11775769-12', 'tableCfgPath' => self::OLDER_PATH],
            ],
        ];
    }

    /** 6★ items of the real-data tests: featured by the irminsul.gg banners or listed by the real pool tables */
    public const array REAL_ITEMS = [
        // Not featured by any banner
        'chr_0002_endminm' => 'Endministrator',
        'chr_0003_endminf' => 'Endministrator',
        'chr_0013_aglina' => 'Gilberta',
        'chr_0016_laevat' => 'Laevatain',
        'chr_0017_yvonne' => 'Yvonne',
        'chr_0025_ardelia' => 'Ardelia',
        'chr_0027_tangtang' => 'Tangtang',
        'chr_0028_wulfa' => 'Rossi',
        'chr_0029_pograni' => 'Pogranichnik',
        'chr_0030_zhuangfy' => 'Zhuang Fangyi',
        'chr_0031_mifu' => 'Mi Fu',
        'chr_0032_lizhiyan' => 'Arcane',
        'chr_0033_camille' => 'Camille',
        'chr_0034_typhoea' => 'Typhoeus',
        'chr_0035_liino' => 'Liino',
        'chr_0036_jsspsi' => 'Si',
        'chr_0037_minghe' => 'Argent Flow',
        'chr_0040_sweetpie' => 'Kay',
        'wpn_claym_0007' => 'Thunderberge',
        'wpn_claym_0013' => 'Khravengger',
        'wpn_claym_0017' => 'Amaranthine Tassel',
        'wpn_claym_0018' => 'Jade Vessel',
        'wpn_funnel_0011' => 'Delivery Guaranteed',
        'wpn_funnel_0013' => 'Dreams of the Starry Beach',
        'wpn_funnel_0015' => 'Lone Barge',
        'wpn_funnel_0016' => 'Type 42 Solemn Phalanx',
        'wpn_funnel_0019' => 'Umbra of Frigid Eventide',
        'wpn_lance_0012' => 'Mountain Bearer',
        'wpn_lance_0014' => 'Bedazzling Night Debut',
        'wpn_lance_0015' => 'Blessing of Lustrous Carmine',
        'wpn_lance_0017' => 'Sweet Deal',
        'wpn_pistol_0010' => 'Artzy Tyrannical',
        'wpn_pistol_0011' => 'Brigand\'s Calling',
        'wpn_sword_0006' => 'Forgeborn Scathe',
        'wpn_sword_0016' => 'Never Rest',
        'wpn_sword_0022' => 'Lupine Scarlet',
        'wpn_sword_0025' => 'Lunar Calling',
    ];

    /**
     * Real pool tables of version 1.7.2@12153128-4 and the REAL_ITEMS (their text ids are made up), served as
     * the latest version.
     *
     * @return array<string, mixed>
     */
    public static function realFiles(): array
    {
        $items = [];
        $texts = [];
        foreach (self::REAL_ITEMS as $itemId => $name) {
            $textId = 7000000 + count($items);
            $items[$itemId] = self::itemRow($itemId, $textId, 6, str_starts_with($itemId, 'chr_') ? 4 : 5);
            $texts[(string) $textId] = $name;
        }

        return [
            self::LATEST_PATH . '/ItemTable.json' => $items,
            self::LATEST_PATH . '/I18nTextTable_EN.json' => $texts,
            self::LATEST_PATH . '/' . self::CHAR_POOL_TABLE => [
                'beginner' => self::poolRow('beginner', 1, 'upCharIds', []),
                'joint_1_2_2' => self::poolRow('joint_1_2_2', 3, 'upCharIds', ['chr_0016_laevat', 'chr_0013_aglina']),
                'rerun_chr_aglina' => self::poolRow('rerun_chr_aglina', 4, 'upCharIds', ['chr_0013_aglina'], 1),
                'rerun_chr_tangtang' => self::poolRow('rerun_chr_tangtang', 4, 'upCharIds', ['chr_0027_tangtang'], 1),
                'rerun_chr_wulfa' => self::poolRow('rerun_chr_wulfa', 4, 'upCharIds', ['chr_0028_wulfa'], 1),
                'rerun_chr_yvonne' => self::poolRow('rerun_chr_yvonne', 4, 'upCharIds', ['chr_0017_yvonne'], 1),
                'special_1_0_1' => self::poolRow('special_1_0_1', 0, 'upCharIds', ['chr_0016_laevat']),
                'special_1_0_2' => self::poolRow('special_1_0_2', 0, 'upCharIds', ['chr_0017_yvonne']),
                'special_1_0_3' => self::poolRow('special_1_0_3', 0, 'upCharIds', ['chr_0013_aglina']),
                'special_1_1_1' => self::poolRow('special_1_1_1', 0, 'upCharIds', ['chr_0027_tangtang']),
                'special_1_1_2' => self::poolRow('special_1_1_2', 0, 'upCharIds', ['chr_0028_wulfa']),
                'special_1_2_1' => self::poolRow('special_1_2_1', 0, 'upCharIds', ['chr_0030_zhuangfy']),
                'special_1_3_1' => self::poolRow('special_1_3_1', 0, 'upCharIds', ['chr_0031_mifu']),
                'special_1_3_2' => self::poolRow('special_1_3_2', 0, 'upCharIds', ['chr_0033_camille']),
                'special_1_4_1' => self::poolRow('special_1_4_1', 0, 'upCharIds', ['chr_0032_lizhiyan']),
                'special_1_4_2' => self::poolRow('special_1_4_2', 0, 'upCharIds', ['chr_0035_liino']),
                'special_1_5_1' => self::poolRow('special_1_5_1', 0, 'upCharIds', ['chr_0034_typhoea']),
                'special_1_6_1' => self::poolRow('special_1_6_1', 0, 'upCharIds', ['chr_0036_jsspsi']),
                'special_1_6_2' => self::poolRow('special_1_6_2', 0, 'upCharIds', ['chr_0037_minghe']),
                'special_1_7_1' => self::poolRow('special_1_7_1', 0, 'upCharIds', ['chr_0040_sweetpie']),
                'standard' => self::poolRow('standard', 2, 'upCharIds', []),
            ],
            self::LATEST_PATH . '/' . self::WEAPON_POOL_TABLE => [
                'rerun_wpn_aglina' => self::poolRow('rerun_wpn_aglina', 1, 'upWeaponIds', ['wpn_funnel_0011'], 1),
                'rerun_wpn_tangtang' => self::poolRow('rerun_wpn_tangtang', 1, 'upWeaponIds', ['wpn_pistol_0011'], 1),
                'rerun_wpn_wulfa' => self::poolRow('rerun_wpn_wulfa', 1, 'upWeaponIds', ['wpn_sword_0022'], 1),
                'rerun_wpn_yvonne' => self::poolRow('rerun_wpn_yvonne', 1, 'upWeaponIds', ['wpn_pistol_0010'], 1),
                'weaponbox_1_6_1' => self::poolRow('weaponbox_1_6_1', 0, 'upWeaponIds', ['wpn_claym_0018']),
                'weaponbox_1_6_2' => self::poolRow('weaponbox_1_6_2', 0, 'upWeaponIds', ['wpn_sword_0025']),
                'weaponbox_1_7_1' => self::poolRow('weaponbox_1_7_1', 0, 'upWeaponIds', ['wpn_lance_0017']),
                'weaponbox_constant_1' => self::poolRow('weaponbox_constant_1', 0, 'upWeaponIds', ['wpn_claym_0013']),
                'weaponbox_constant_2' => self::poolRow('weaponbox_constant_2', 0, 'upWeaponIds', ['wpn_funnel_0013']),
                'weaponbox_constant_3' => self::poolRow('weaponbox_constant_3', 0, 'upWeaponIds', ['wpn_sword_0016']),
                'weaponbox_constant_4' => self::poolRow('weaponbox_constant_4', 0, 'upWeaponIds', ['wpn_lance_0012']),
                'weaponbox_constant_5' => self::poolRow('weaponbox_constant_5', 0, 'upWeaponIds', ['wpn_claym_0007']),
                'weponbox_1_0_1' => self::poolRow('weponbox_1_0_1', 0, 'upWeaponIds', ['wpn_sword_0006']),
                'weponbox_1_0_2' => self::poolRow('weponbox_1_0_2', 0, 'upWeaponIds', ['wpn_pistol_0010']),
                'weponbox_1_0_3' => self::poolRow('weponbox_1_0_3', 0, 'upWeaponIds', ['wpn_funnel_0011']),
                'weponbox_1_1_1' => self::poolRow('weponbox_1_1_1', 0, 'upWeaponIds', ['wpn_pistol_0011']),
                'weponbox_1_1_2' => self::poolRow('weponbox_1_1_2', 0, 'upWeaponIds', ['wpn_sword_0022']),
                'weponbox_1_2_1' => self::poolRow('weponbox_1_2_1', 0, 'upWeaponIds', ['wpn_funnel_0015']),
                'weponbox_1_2_2' => self::poolRow('weponbox_1_2_2', 0, 'upWeaponIds', ['wpn_sword_0006']),
                'weponbox_1_2_3' => self::poolRow('weponbox_1_2_3', 0, 'upWeaponIds', ['wpn_funnel_0011']),
                'weponbox_1_3_1' => self::poolRow('weponbox_1_3_1', 0, 'upWeaponIds', ['wpn_claym_0017']),
                'weponbox_1_3_2' => self::poolRow('weponbox_1_3_2', 0, 'upWeaponIds', ['wpn_lance_0015']),
                'weponbox_1_4_1' => self::poolRow('weponbox_1_4_1', 0, 'upWeaponIds', ['wpn_funnel_0016']),
                'weponbox_1_4_2' => self::poolRow('weponbox_1_4_2', 0, 'upWeaponIds', ['wpn_lance_0014']),
                'weponbox_1_5_1' => self::poolRow('weponbox_1_5_1', 0, 'upWeaponIds', ['wpn_funnel_0019']),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaultFiles(): array
    {
        return [
            'manifest.json' => self::manifest(),
            self::LATEST_PATH . '/ItemTable.json' => [
                'achv_adv_tundra_box_1' => self::itemRow('achv_adv_tundra_box_1', -8604254710766028328, 1, 100),
                'chr_0005_chen' => self::itemRow('chr_0005_chen', -5740150819386453598, 5, 4),
                'chr_0009_azrila' => self::itemRow('chr_0009_azrila', -8646050467824776477, 6, 4),
                'chr_0002_endminm' => self::itemRow('chr_0002_endminm', -5414477179273912738, 6, 4),
                'chr_0013_aglina' => self::itemRow('chr_0013_aglina', 9107482563868521928, 6, 4),
                // Blank English text, no text id at all
                'chr_0098_blank' => self::itemRow('chr_0098_blank', 1234, 5, 4),
                'chr_0099_noname' => self::itemRow('chr_0099_noname', 0, 5, 4),
                'wpn_funnel_0011' => self::itemRow('wpn_funnel_0011', -8469995341331243725, 6, 5),
                'wpn_claym_0003' => self::itemRow('wpn_claym_0003', -5195668008198752422, 4, 5),
                // Text id missing from the English table
                'wpn_pistol_0008' => self::itemRow('wpn_pistol_0008', -5318664704038443001, 6, 5),
                'item_gold' => self::itemRow('item_gold', 42, 3, 1),
            ],
            self::LATEST_PATH . '/I18nTextTable_EN.json' => [
                '-9223254181335467105' => 'Reach cabin Lv.%s to unlock',
                '-8604254710766028328' => 'Valley Crate Master',
                '-5740150819386453598' => 'Chen Qianyu',
                '-8646050467824776477' => 'Ember',
                '-5414477179273912738' => 'The Endministrator',
                '9107482563868521928' => 'Gilberta',
                '1234' => '   ',
                '-8469995341331243725' => 'Delivery Guaranteed',
                '-5195668008198752422' => ' Industry 0.1 ',
                '42' => 'T-Creds',
            ],
            self::LATEST_PATH . '/' . self::CHAR_POOL_TABLE => [
                'beginner' => self::poolRow('beginner', 1, 'upCharIds', []),
                'rerun_chr_aglina' => self::poolRow('rerun_chr_aglina', 4, 'upCharIds', ['chr_0013_aglina'], 1),
                // Non-string and blank rate-up ids are dropped
                'special_1_0_1' => self::poolRow('special_1_0_1', 0, 'upCharIds', ['chr_0016_laevat', 42, '']),
                'joint_1_2_2' => self::poolRow('joint_1_2_2', 3, 'upCharIds', ['chr_0016_laevat', 'chr_0013_aglina']),
                'special_1_0_3' => self::poolRow('special_1_0_3', 0, 'upCharIds', ['chr_0013_aglina']),
                // No integer type: the row is skipped instead of being read as a special pool
                'special_9_9_9' => self::poolRow('special_9_9_9', '0', 'upCharIds', ['chr_0009_azrila']),
                'standard' => self::poolRow('standard', 2, 'upCharIds', []),
            ],
            self::LATEST_PATH . '/' . self::WEAPON_POOL_TABLE => [
                'rerun_wpn_aglina' => self::poolRow('rerun_wpn_aglina', 1, 'upWeaponIds', ['wpn_funnel_0011'], 1),
                'weponbox_1_0_3' => self::poolRow('weponbox_1_0_3', 0, 'upWeaponIds', ['wpn_funnel_0011']),
                'weaponbox_constant_1' => self::poolRow('weaponbox_constant_1', 0, 'upWeaponIds', ['wpn_claym_0013']),
            ],
            // The older version has no pool table: its build logs a warning and stores no pool
            self::OLDER_PATH . '/ItemTable.json' => [
                'chr_0005_chen' => self::itemRow('chr_0005_chen', -5740150819386453598, 5, 4),
            ],
            self::OLDER_PATH . '/I18nTextTable_EN.json' => [
                '-5740150819386453598' => 'Chen Qianyu',
            ],
        ];
    }
}
