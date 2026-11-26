<?php

namespace App\Tests\GryphlineBundle\Service\Catalog;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Tiny fake AkeData site served through MockHttpClient (rows and names copied from version 1.6.4@11944529-13).
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
