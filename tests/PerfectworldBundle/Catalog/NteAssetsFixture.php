<?php

namespace App\Tests\PerfectworldBundle\Catalog;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Tiny fake NTE_Assets repository served through MockHttpClient.
 */
final class NteAssetsFixture
{
    public const string SHA = '0123456789abcdef0123456789abcdef01234567';
    public const string RAW_PREFIX = 'https://raw.githubusercontent.com/Waifus-Grace/NTE_Assets/' . self::SHA . '/';
    public const string GLOBAL_README = "# NTE_Assets\n\nThere will be various assets in this repository.\n\n> Version: 1.5.4 (Global)\n";

    /** @var list<string> */
    public array $requestedUrls = [];

    /** @var list<string> */
    public array $apiAcceptHeaders = [];

    /**
     * @param array<string, mixed> $files repository path => decoded JSON (or raw string); null = 404
     */
    public function __construct(public array $files = [])
    {
        $this->files = array_replace(self::defaultFiles(), $files);
    }

    public function client(): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $this->requestedUrls[] = $url;

            if (str_starts_with($url, 'https://api.github.com/repos/Waifus-Grace/NTE_Assets/commits/')) {
                foreach ($options['headers'] ?? [] as $header) {
                    if (stripos($header, 'Accept:') === 0) {
                        $this->apiAcceptHeaders[] = trim(substr($header, 7));
                    }
                }

                return new MockResponse(self::SHA, ['response_headers' => ['content-type' => 'application/vnd.github.sha']]);
            }

            if (!str_starts_with($url, self::RAW_PREFIX)) {
                return new MockResponse('Not Found', ['http_code' => 404]);
            }

            $path = rawurldecode(substr($url, strlen(self::RAW_PREFIX)));
            $content = $this->files[$path] ?? null;
            if ($content === null) {
                return new MockResponse('404: Not Found', ['http_code' => 404]);
            }

            return new MockResponse(
                is_string($content) ? $content : json_encode($content, \JSON_THROW_ON_ERROR),
                ['response_headers' => ['content-type' => 'text/plain; charset=utf-8']],
            );
        });
    }

    /**
     * @return list<string> repository paths downloaded from raw.githubusercontent.com
     */
    public function requestedRawPaths(): array
    {
        return array_values(array_map(
            static fn (string $url): string => substr($url, strlen(self::RAW_PREFIX)),
            array_filter($this->requestedUrls, static fn (string $url): bool => str_starts_with($url, self::RAW_PREFIX)),
        ));
    }

    /**
     * @param array<string, mixed> $rows
     *
     * @return list<array<string, mixed>>
     */
    public static function table(string $name, array $rows): array
    {
        return [[
            'Type' => 'DataTable',
            'Name' => $name,
            'Package' => '/Game/DataTable/' . $name,
            'Rows' => $rows,
        ]];
    }

    /**
     * @return array{TableId: string, Key: string, SourceString: string, LocalizedString: string}
     */
    public static function text(string $tableId, string $key, string $localized = ''): array
    {
        return ['TableId' => $tableId, 'Key' => $key, 'SourceString' => '', 'LocalizedString' => $localized];
    }

    /**
     * @return array{AssetPathName: string, SubPathString: string}
     */
    public static function asset(string $path): array
    {
        return ['AssetPathName' => $path, 'SubPathString' => ''];
    }

    /**
     * @return array<string, mixed>
     */
    public static function itemRow(array $name, string $quality, string $icon, string $itemType = 'EItemType::ITEM_TYPE_CONSUMABLE'): array
    {
        return [
            'ItemType' => $itemType,
            'ItemIcon' => self::asset($icon),
            'ItemName' => $name,
            'ItemQuality' => $quality === '' ? '' : 'EItemQuality::ITEM_QUALITY_' . $quality,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaultFiles(): array
    {
        return [
            'README.md' => self::GLOBAL_README,
            'Localization/en/game.json' => [
                'ST_Player' => ['Role_Name_042' => 'Blackbird', 'Role_Name_024' => ' '],
                'ST_Fork' => ['fork_twinbirds_name' => 'Crime and Punishment'],
                'ST_Item' => ['DiceNormal_Name' => 'Fabricated Dice', 'Annulith_Name' => 'Annulith', 'Green_Name' => 'Green Thing', 'BadIcon_Name' => 'Bad Icon'],
                'ST_Appearance' => ['Glide_6_Name' => 'Sheepcopter', 'Glide_1_Name' => 'Paper Wings', 'Fashion_1042_1_Name' => 'Wandering Stars', 'Fashion_1003_0_Name' => 'Default Outfit'],
                'ST_VehicleData' => ['V002_name' => 'Griffin'],
                // Keys outside the namespace of their TableId: found by the key-only lookup
                'ST_Other' => ['Role_Name_024' => 'Zankou', 'fork_moved_name' => 'Be Happy'],
                'ST_Ui' => [
                    'ui_forklottery_up_heiyu_01' => 'Foretold Special',
                    'Lottery_kachimingcheng_heiyu' => 'Foretold Finale',
                    'Lottery_Kachimingcheng_ahq' => 'Fading Reverie',
                    'Lottery_Kachimingcheng_changzhu' => 'Strange Encounters',
                ],
            ],
            'DataTable/Character/DT_Character.json' => self::table('DT_Character', [
                '1042' => self::itemRow(self::text('/Game/Text/ST_Player.ST_Player', 'Role_Name_042', 'Stale Blackbird'), 'ORANGE', '/Game/UI/UI_Icon/AvatarImage/200/player_heiyu.player_heiyu', 'EItemType::ITEM_TYPE_CHARACTER'),
                // Blank in its namespace, present in another one
                '1024' => self::itemRow(self::text('/Game/Text/ST_Player.ST_Player', 'Role_Name_024', 'Stale Zankou'), 'PURPLE', '/Game/UI/UI_Icon/AvatarImage/200/player_zankou.player_zankou', 'EItemType::ITEM_TYPE_CHARACTER'),
                // Key unknown everywhere: embedded string as last resort
                '1098' => self::itemRow(self::text('/Game/Text/ST_Player.ST_Player', 'Role_Name_098', 'Embedded Name'), 'ORANGE', '/Game/UI/UI_Icon/AvatarImage/200/player_x.player_x', 'EItemType::ITEM_TYPE_CHARACTER'),
                // No usable name at all: dropped
                '1099' => self::itemRow(self::text('/Game/Text/ST_Player.ST_Player', 'Role_Name_099'), 'ORANGE', '/Game/UI/UI_Icon/AvatarImage/200/player_y.player_y', 'EItemType::ITEM_TYPE_CHARACTER'),
            ]),
            'DataTable/Fork/DT_ForkItemData.json' => self::table('DT_ForkItemData', [
                'fork_twinbirds' => self::itemRow(self::text('/Game/Text/ST_Fork.ST_Fork', 'fork_twinbirds_name'), 'ORANGE', '/Game/UI/UI_Icon/Fork/fork_twinbirds_256.fork_twinbirds_256', 'EItemType::ITEM_TYPE_FORK'),
                'fork_vine' => self::itemRow(self::text('/Game/Text/ST_Fork.ST_Fork', 'fork_moved_name'), 'BLUE', '/Game/UI/UI_Icon/Fork/fork_HugVine_256.fork_HugVine_256', 'EItemType::ITEM_TYPE_FORK'),
            ]),
            'DataTable/Inventory/DT_ItemConfig.json' => self::table('DT_ItemConfig', [
                // Object name differs in case from the file name
                'DiceNormal' => self::itemRow(self::text('/Game/Text/ST_Item.ST_Item', 'DiceNormal_Name'), 'ORANGE', '/Game/UI/UI_Icon/Item/DiceNormal.DIceNormal', 'EItemType::ITEM_TYPE_Lottery'),
                'Fashion_glide_1075' => self::itemRow(self::text('/Game/Text/ST_Appearance.ST_Appearance', 'Glide_6_Name'), 'PURPLE', '/Game/UI/UI/Appearance/Glider/Fashion_Glide_1075.Fashion_glide_1075', 'EItemType::ITEM_TYPE_GLIDE'),
                // Cosmetic by item type even without the Fashion_ prefix
                'Glide_1' => self::itemRow(self::text('/Game/Text/ST_Appearance.ST_Appearance', 'Glide_1_Name'), 'BLUE', '/Game/UI/UI_Icon/Item/Glide_1.Glide_1', 'EItemType::ITEM_TYPE_GLIDE'),
                // No icon and no quality here: DT_AppearanceData fills them
                'Fashion_1042_1' => self::itemRow(self::text('/Game/Text/ST_Appearance.ST_Appearance', 'Fashion_1042_1_Name'), '', '', 'EItemType::ITEM_TYPE_FASHION'),
                'Green_item' => self::itemRow(self::text('/Game/Text/ST_Item.ST_Item', 'Green_Name'), 'GREEN', '/Game/UI/UI_Icon/Item/green.green'),
                'Bad_icon' => self::itemRow(self::text('/Game/Text/ST_Item.ST_Item', 'BadIcon_Name'), 'BLUE', '/Game/Characters/Player/mesh.mesh'),
            ]),
            'DataTable/Inventory/DT_CapitalItemConfig.json' => self::table('DT_CapitalItemConfig', [
                'Annulith' => self::itemRow(self::text('/Game/Text/ST_Item.ST_Item', 'Annulith_Name'), 'ORANGE', '/Game/UI/UI_Icon/Item/annulith.annulith'),
            ]),
            'DataTable/Vehicle/DT_VehicleItemData.json' => self::table('DT_VehicleItemData', [
                'vehicle002' => self::itemRow(self::text('/Game/Text/ST_VehicleData.ST_VehicleData', 'V002_name'), 'ORANGE', '/Game/UI/UI_Icon/Item/YH_UI_CarCard1.YH_UI_CarCard1', 'EItemType::ITEM_TYPE_VEHICLE_KEY'),
            ]),
            'DataTable/Character/Appearance/DT_AppearanceData.json' => self::table('DT_AppearanceData', [
                'Fashion_1042_1' => [
                    'Name' => self::text('/Game/Text/ST_Appearance.ST_Appearance', 'Fashion_1042_1_Name'),
                    'Quality' => 'EItemQuality::ITEM_QUALITY_PURPLE',
                    'ItemIcon' => self::asset('/Game/UI/UI_Icon/AvatarImage/256/player_heiyu1_256.player_heiyu1_256'),
                ],
                'Fashion_1003_0' => [
                    'Name' => self::text('/Game/Text/ST_Appearance.ST_Appearance', 'Fashion_1003_0_Name'),
                    'Quality' => 'EItemQuality::ITEM_QUALITY_PURPLE',
                    'ItemIcon' => self::asset(''),
                    'HeadIconBig' => self::asset('/Game/UI/UI_Icon/AvatarImage/256/player_sakiri_256.player_sakiri_256'),
                ],
            ]),
            'DataTable/Avarta/AvatarDataTable.json' => self::table('AvatarDataTable', [
                'Fashion_1042_1' => [
                    'Name' => self::text('/Game/Text/ST_Appearance.ST_Appearance', 'Fashion_1042_1_Name'),
                    'BigImage' => self::asset('/Game/UI/UI_Icon/AvatarImage/256/player_heiyu1_256.player_heiyu1_256'),
                    'icon' => self::asset('/Game/UI/UI_Icon/AvatarImage/256/player_heiyu1_256.player_heiyu1_256'),
                ],
                '1042' => [
                    'BigImage' => self::asset('/Game/UI/UI_Icon/AvatarImage/256/player_heiyu_256.player_heiyu_256'),
                    'icon' => self::asset(''),
                ],
            ]),
            'DataTable/Fork/DT_ForkLotteryPoolData.json' => self::table('DT_ForkLotteryPoolData', [
                'ForkLottery_HeiYu' => [
                    'Name' => self::text('/Game/Text/ST_Ui.ST_Ui', 'ui_forkshop_24', 'Miracle Box'),
                    'ShowText1' => self::text('/Game/Text/ST_Ui.ST_Ui', 'ui_forklottery_up_heiyu_01'),
                ],
                '1' => [
                    'Name' => self::text('/Game/Text/ST_Ui.ST_Ui', 'ui_forkshop_24', 'Miracle Box'),
                ],
            ]),
        ];
    }
}
