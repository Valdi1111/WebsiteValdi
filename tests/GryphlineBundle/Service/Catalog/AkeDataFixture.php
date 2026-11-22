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
            self::OLDER_PATH . '/ItemTable.json' => [
                'chr_0005_chen' => self::itemRow('chr_0005_chen', -5740150819386453598, 5, 4),
            ],
            self::OLDER_PATH . '/I18nTextTable_EN.json' => [
                '-5740150819386453598' => 'Chen Qianyu',
            ],
        ];
    }
}
