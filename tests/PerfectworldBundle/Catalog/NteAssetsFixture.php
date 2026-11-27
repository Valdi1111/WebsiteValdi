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
     * GachaIllustrate row: the illustration ("ItemIcon") and fields the catalog ignores.
     *
     * @return array<string, mixed>
     */
    public static function illustration(string $path): array
    {
        return [
            'ItemIcon' => self::asset($path),
            'CharacterSequence' => self::asset('/Game/sequence_pack/cutscenes/CardDrawSettlement/sequence_getplayer.sequence_getplayer'),
            'HeadIcon' => self::asset('/Game/UI/UI_Icon/AvatarImage/200/player_x.player_x'),
        ];
    }

    /**
     * Banner tables of the real 1.5.4 Global datamine (c457cb6), reduced to what the banners are built from:
     * the 12 Arc pools (rate-up Arc, name), the 13 board pools with the guarantee texts naming the
     * featured characters, and the featured S characters and Arcs. Merge it over the default files.
     *
     * @return array<string, mixed>
     */
    public static function realBannerFiles(): array
    {
        // Pool suffix => [Arc pool name, rate-up Arc id, Arc name, board featured id, character name, guarantee key suffix, board name]
        $rotations = [
            'Nanali' => ['Tiger Special', 'fork_TigerTally', 'Ready-Ready', '1010', 'Nanally', '', 'The Ichi-daime'],
            'Xun' => ['Bright Moon Special', 'fork_Time', 'Marching Beyond Time', '1052', 'Hotori', 'Xun', 'Misty Tipsy Style'],
            'AnHunQu' => ['Nocturne Special', 'fork_Rose', 'The Last Rose', '1004', 'Lacrimosa', 'AnHunQu', 'Fading Reverie'],
            'Kaesi' => ['Pursuit Special', 'fork_GoldWool', "What's Desired", '1071', 'Chaos', 'Kaesi', 'Forsaken Path'],
            'Zhenhong' => ['Resolve Special', 'fork_LunarPhase', 'Blushing Mirage', '1076', 'Shinku', 'ZhenHong', 'Before the Dawn'],
            'Yiluoyi' => ['Dreamgate Special', 'fork_Door', 'The Wrong Gate', '1075', 'Iroi', 'Yiluoyi', 'The Lifeline'],
            'Canhong' => ['Spellbound Special', 'fork_DemonBlade', 'Ravenous Blade', '1036', 'Zankou', 'CanHong', 'Alluring Shadows'],
            'LingKe' => ['Soundscape Special', 'fork_GoldRecord', 'Voice of the Voyager', '1072', 'Linko', 'LingKe', 'Surfing All Channels!'],
            'HeiYu' => ['Roostfeather Special', 'fork_twinbirds', 'Crime and Punishment', '1042', 'Blackbird', 'HeiYu', 'Foretold Finale'],
            'MingYin' => ['Celestial Special', 'fork_prism', 'Far Side of the Moon', '1057', 'Akane Rin', 'MingYin', 'Dazzling Star'],
            'YeErMu' => ['Echo Special', 'fork_elyms', 'Echoes on Hold', '1090', 'Elyms', 'YeErMu', 'Android Dreams'],
            'Exe' => ['Encryption Special', 'fork_exe', 'Dotted Horizon', '1074', 'Exe', 'Exe', 'Offline Equations'],
        ];
        // The board pool ids do not always share the case of the Arc pool ids
        $boardIds = ['Zhenhong' => 'Lottery_ZhenHong'];

        $defaults = self::defaultFiles();
        $localization = $defaults['Localization/en/game.json'];
        $characters = $defaults['DataTable/Character/DT_Character.json'][0]['Rows'];
        $arcs = $defaults['DataTable/Fork/DT_ForkItemData.json'][0]['Rows'];
        $arcPools = [];
        $poolDrops = [self::poolDrop('Lottery_Permanent')];
        $localization['ST_Ui']['BPUI_LotteryModuleEntrance_BigGurantten_Changzhu'] = 'Guaranteed <Orange>S-Class</> Character within roll ×<Blue>{0}</>';

        foreach ($rotations as $suffix => [$arcPoolName, $arcId, $arcName, $characterId, $characterName, $guaranteeSuffix, $boardName]) {
            $nameKey = sprintf('Role_Name_%03d', (int) $characterId - 1000);
            $localization['ST_Player'][$nameKey] = $characterName;
            $characters[$characterId] = self::itemRow(self::text('/Game/Text/ST_Player.ST_Player', $nameKey), 'ORANGE', sprintf('/Game/UI/UI_Icon/AvatarImage/200/player_%1$s.player_%1$s', strtolower($suffix)), 'EItemType::ITEM_TYPE_CHARACTER') + ['bIsInvite' => true];

            $localization['ST_Fork'][$arcId . '_name'] = $arcName;
            $arcs[$arcId] = self::itemRow(self::text('/Game/Text/ST_Fork.ST_Fork', $arcId . '_name'), 'ORANGE', sprintf('/Game/UI/UI_Icon/Fork/%1$s_256.%1$s_256', $arcId), 'EItemType::ITEM_TYPE_FORK');

            $arcPools['ForkLottery_' . $suffix] = [
                'Name' => self::text('/Game/Text/ST_Ui.ST_Ui', 'ui_forkshop_24', 'Miracle Box'),
                'ShowText1' => self::text('/Game/Text/ST_Ui.ST_Ui', sprintf('ui_forklottery_up_%s_01', strtolower($suffix)), $arcPoolName),
                'UpList' => [$arcId],
                'UpGuaranteeCnt' => 80,
                'ShowRewards' => [['ItemID' => $arcId, 'IsUp' => true], ['ItemID' => 'fork_Butterfly', 'IsUp' => false]],
            ];

            $poolDrops[] = self::poolDrop($boardIds[$suffix] ?? 'Lottery_' . $suffix);
            $guaranteeKey = 'BPUI_LotteryModuleEntrance_BigGurantten' . ($guaranteeSuffix !== '' ? '_' . $guaranteeSuffix : '');
            $localization['ST_Ui'][$guaranteeKey] = sprintf('Guaranteed <Orange>S-Class</> Character <Red>%s</> within roll ×<Blue>{0}</>', $characterName);
            $localization['ST_Ui']['Lottery_Kachimingcheng_' . strtolower($suffix)] = $boardName;
        }

        // A standard S Arc of every Arc pool
        $localization['ST_Fork']['fork_Butterfly_name'] = 'Reality Refuge';
        $arcs['fork_Butterfly'] = self::itemRow(self::text('/Game/Text/ST_Fork.ST_Fork', 'fork_Butterfly_name'), 'ORANGE', '/Game/UI/UI_Icon/Fork/fork_Butterfly_256.fork_Butterfly_256', 'EItemType::ITEM_TYPE_FORK');
        // Row "1" repeats the launch pool
        $arcPools['1'] = $arcPools['ForkLottery_Nanali'];

        return [
            'Localization/en/game.json' => $localization,
            'DataTable/Character/DT_Character.json' => self::table('DT_Character', $characters),
            'DataTable/Fork/DT_ForkItemData.json' => self::table('DT_ForkItemData', $arcs),
            'DataTable/Fork/DT_ForkLotteryPoolData.json' => self::table('DT_ForkLotteryPoolData', $arcPools),
            'DataTable/Monopoly/DT_MonopolyCellDataTable.json' => self::table('DT_MonopolyCellDataTable', [
                '9' => ['CellID' => '9', 'PoolDropDatas' => $poolDrops],
            ]),
        ];
    }

    /**
     * @return array{Key: string, Value: array<string, mixed>}
     */
    public static function poolDrop(string $poolId): array
    {
        return ['Key' => $poolId, 'Value' => ['MapDropDatas' => [['Key' => 'EMonopolyMapType::EMMT_Base_Map', 'Value' => 'Board_Chess_LessMiDie']]]];
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
            // Shapes of the real table (c457cb6): gacha splash, outfit art in either folder, the Arcs and dice
            // pointing at their own icon, illustrated cosmetics; keys do not always share the case of the item ids
            'DataTable/Gacha/GachaIllustrate.json' => self::table('GachaIllustrate', [
                '1024' => self::illustration('/Game/UI/UI/Gacha/YH_lihui_character_zankou.YH_lihui_character_zankou'),
                '1042' => self::illustration('/Game/UI/UI/Appearance/Fashion/1024/YH_lihui_fashionshop_heiyu.YH_lihui_fashionshop_heiyu'),
                '1098' => self::illustration('/Game/UI/UI_Icon/Appearance/1098/Fashion_1098_0/1024_Fashion_1098_0.1024_Fashion_1098_0'),
                // Dropped item (no name): no entry to add a preview to
                '1099' => self::illustration('/Game/UI/UI/Gacha/YH_lihui_character_y.YH_lihui_character_y'),
                'fork_twinbirds' => self::illustration('/Game/UI/UI_Icon/Fork/fork_twinbirds_256.fork_twinbirds_256'),
                'fork_Vine' => self::illustration('/Game/UI/UI_Icon/Fork/fork_HugVine_256.fork_HugVine_256'),
                // The icon itself, in another case
                'DIceNormal' => self::illustration('/Game/UI/UI_Icon/item/DICENormal.DIceNormal'),
                'Fashion_Glide_1075' => self::illustration('/Game/UI/UI/Gacha/Lottery_Img/Yiluoyi/YH_lihui_1024_Yiluoyi1.YH_lihui_1024_Yiluoyi1'),
                // Same as the icon DT_AppearanceData fills after DT_ItemConfig: no preview
                'Fashion_1042_1' => self::illustration('/Game/UI/UI_Icon/AvatarImage/256/player_heiyu1_256.player_heiyu1_256'),
                // Not a UI texture: ignored
                'Annulith' => self::illustration('/Game/Characters/Player/mesh.mesh'),
                'Not_in_catalog' => self::illustration('/Game/UI/UI/Gacha/YH_lihui_character_z.YH_lihui_character_z'),
            ]),
        ];
    }
}
