<?php

namespace App\Tests\HoyoverseBundle\Service\Endgame;

/**
 * HoYoLAB response bodies of the Honkai: Star Rail endgame records (game_record/hkrpg): the structure of the real
 * responses, limited to the fields the service reads and a few more, with invented values.
 */
final class HonkaiStarRailEndgameFixture
{
    /** Memory of Chaos period, server time of an EU profile (UTC+01:00) */
    public const array MEMORY_OF_CHAOS_BEGIN = ['year' => 2026, 'month' => 11, 'day' => 2, 'hour' => 4, 'minute' => 0];
    public const array MEMORY_OF_CHAOS_END = ['year' => 2026, 'month' => 12, 'day' => 14, 'hour' => 4, 'minute' => 0];

    /** Pure Fiction period */
    public const array PURE_FICTION_BEGIN = ['year' => 2026, 'month' => 10, 'day' => 19, 'hour' => 4, 'minute' => 0];
    public const array PURE_FICTION_END = ['year' => 2026, 'month' => 11, 'day' => 30, 'hour' => 4, 'minute' => 0];

    /** Apocalyptic Shadow period */
    public const array APOCALYPTIC_SHADOW_BEGIN = ['year' => 2026, 'month' => 11, 'day' => 16, 'hour' => 4, 'minute' => 0];
    public const array APOCALYPTIC_SHADOW_END = ['year' => 2026, 'month' => 12, 'day' => 28, 'hour' => 4, 'minute' => 0];

    /** Anomaly Arbitration run: it ends at 23:00 and the next one starts at 00:00 */
    public const array ANOMALY_ARBITRATION_BEGIN = ['year' => 2026, 'month' => 11, 'day' => 11, 'hour' => 0, 'minute' => 0];
    public const array ANOMALY_ARBITRATION_END = ['year' => 2026, 'month' => 12, 'day' => 22, 'hour' => 23, 'minute' => 0];

    /** Date object the game sends when there is no date */
    public const array EMPTY_DATE = ['year' => 0, 'month' => 0, 'day' => 0, 'hour' => 0, 'minute' => 0];

    /**
     * One entry of groups[]: a period of Memory of Chaos, Pure Fiction or Apocalyptic Shadow.
     *
     * @param array<string, int> $begin
     * @param array<string, int> $end
     */
    public static function group(int $scheduleId, array $begin, array $end, string $status = 'Running'): array
    {
        return [
            'schedule_id' => $scheduleId,
            'begin_time' => $begin,
            'end_time' => $end,
            'status' => $status,
            'name_mi18n' => 'Example Period',
            'upper_boss' => null,
            'lower_boss' => null,
            'tierce_boss' => null,
        ];
    }

    /**
     * One stage of all_floor_detail[].
     *
     * @param int  $stars      stars of the response: the base stars plus the Prismatic Star
     * @param bool $stringStars as Apocalyptic Shadow does: stars as strings
     */
    public static function floor(string $name, int $stars, int $extraStars = 0, bool $fast = false, bool $tierce = false, bool $stringStars = false): array
    {
        return [
            'name' => $name,
            'round_num' => $fast ? 0 : 3,
            'star_num' => $stringStars ? (string) $stars : $stars,
            // A quick-cleared stage keeps the two nodes, without avatars, and has no third one
            'node_1' => self::node(1101, 1, $fast),
            'node_2' => self::node(1102, 2, $fast),
            'is_chaos' => true,
            'maze_id' => 9001,
            'is_fast' => $fast,
            'node_3' => $fast ? null : self::node(1103, 3),
            'extra_star_num' => $stringStars ? (string) $extraStars : $extraStars,
            'is_tierce' => $tierce,
        ];
    }

    /**
     * The twelve stages of a Memory of Chaos at the maximum, highest first: the last one played in Starward Mode.
     *
     * @return list<array<string, mixed>>
     */
    public static function memoryOfChaosFloors(bool $starward = true): array
    {
        $floors = [];
        foreach (['XII', 'XI', 'X', 'IX', 'VIII', 'VII', 'VI', 'V', 'IV', 'III', 'II', 'I'] as $index => $roman) {
            $floors[] = $index === 0 && $starward
                ? self::floor("Example Memory ($roman)Starward Mode", 4, 1, tierce: true)
                : self::floor("Example Memory ($roman)", 3, fast: $index > 2);
        }

        return $floors;
    }

    /**
     * Body of challenge, challenge_story or challenge_boss (schedule_type=1, need_all=true).
     *
     * @param list<array<string, mixed>> $groups periods, the current one first
     * @param list<array<string, mixed>> $floors stages reached
     */
    public static function challenge(array $groups, array $floors = [], int $stars = 0, int $extraStars = 0, int $battles = 0): array
    {
        return [
            'retcode' => 0,
            'message' => 'OK',
            'data' => [
                'groups' => $groups,
                'star_num' => $stars,
                'max_floor' => $floors === [] ? '' : 'Example Stage',
                'battle_num' => $battles,
                'has_data' => $floors !== [],
                'all_floor_detail' => $floors,
                'max_floor_id' => $floors === [] ? 0 : 9001,
                'extra_star_num' => $extraStars,
            ],
        ];
    }

    /**
     * Memory of Chaos at the maximum, Starward Mode played.
     */
    public static function memoryOfChaos(?array $floors = null, int $stars = 36, int $extraStars = 1, int $battles = 3): array
    {
        $body = self::challenge(self::memoryOfChaosGroups(), $floors ?? self::memoryOfChaosFloors(), $stars, $extraStars, $battles);
        // Memory of Chaos repeats its period at the top level
        $body['data']['schedule_id'] = 9011;
        $body['data']['begin_time'] = self::MEMORY_OF_CHAOS_BEGIN;
        $body['data']['end_time'] = self::MEMORY_OF_CHAOS_END;

        return $body;
    }

    /**
     * Memory of Chaos of a player who did not play: no data, but the periods are there.
     */
    public static function memoryOfChaosNotPlayed(): array
    {
        return self::memoryOfChaos([], 0, 0, 0);
    }

    /**
     * @return list<array<string, mixed>> current period, then the previous one
     */
    public static function memoryOfChaosGroups(): array
    {
        return [
            self::group(9011, self::MEMORY_OF_CHAOS_BEGIN, self::MEMORY_OF_CHAOS_END),
            self::group(9010, ['year' => 2026, 'month' => 9, 'day' => 28, 'hour' => 6, 'minute' => 0], self::MEMORY_OF_CHAOS_BEGIN, 'End'),
        ];
    }

    /**
     * Pure Fiction at the maximum (four stages, the last one in Starward Mode), or with the given stages.
     */
    public static function pureFiction(?array $floors = null, int $stars = 12, int $extraStars = 1, int $battles = 2): array
    {
        return self::challenge([
            self::group(9021, self::PURE_FICTION_BEGIN, self::PURE_FICTION_END),
            self::group(9020, ['year' => 2026, 'month' => 9, 'day' => 14, 'hour' => 4, 'minute' => 0], self::PURE_FICTION_BEGIN, 'End'),
        ], $floors ?? [
            self::floor('Example Fiction (IV)Starward Mode', 4, 1, tierce: true),
            self::floor('Example Fiction (III)', 3),
            self::floor('Example Fiction (II)', 3, fast: true),
            self::floor('Example Fiction (I)', 3, fast: true),
        ], $stars, $extraStars, $battles);
    }

    /**
     * Apocalyptic Shadow at the maximum: stars as strings in the stages (and only there), as the game sends them.
     */
    public static function apocalypticShadow(?array $floors = null, int $stars = 12, int $extraStars = 1, int $battles = 3): array
    {
        return self::challenge([
            self::group(9031, self::APOCALYPTIC_SHADOW_BEGIN, self::APOCALYPTIC_SHADOW_END, 'New'),
            self::group(9030, ['year' => 2026, 'month' => 10, 'day' => 5, 'hour' => 4, 'minute' => 0], self::APOCALYPTIC_SHADOW_BEGIN, 'End'),
        ], $floors ?? [
            self::floor('Example Shadow: Difficulty 4Starward Mode', 4, 1, tierce: true, stringStars: true),
            self::floor('Example Shadow: Difficulty 3', 3, stringStars: true),
            self::floor('Example Shadow: Difficulty 2', 3, fast: true, stringStars: true),
            self::floor('Example Shadow: Difficulty 1', 3, fast: true, stringStars: true),
        ], $stars, $extraStars, $battles);
    }

    /**
     * Body of challenge_peak (schedule_type=1): the current run of Anomaly Arbitration.
     *
     * @param int|null $bossStars null = the King was not fought (boss_record null)
     */
    public static function anomalyArbitration(
        int $mobStars = 9,
        ?int $bossStars = 3,
        bool $plight = false,
        bool $played = true,
        array $begin = self::ANOMALY_ARBITRATION_BEGIN,
        array $end = self::ANOMALY_ARBITRATION_END,
    ): array {
        return [
            'retcode' => 0,
            'message' => 'OK',
            'data' => [
                'challenge_peak_records' => [self::anomalyArbitrationRecord($mobStars, $bossStars, $plight, $played, $begin, $end)],
                'has_more_boss_record' => false,
                'challenge_peak_best_record_brief' => [
                    'total_battle_num' => 7,
                    'mob_stars' => $mobStars,
                    'boss_stars' => $bossStars ?? 0,
                    'challenge_peak_rank_icon_type' => 'ChallengePeakRankIconTypeSilver',
                    'challenge_peak_rank_icon' => 'https://example.com/rank.png',
                ],
                'role' => ['server' => 'prod_official_eur', 'nickname' => 'Proxy', 'level' => 70, 'role_id' => '700000001'],
            ],
        ];
    }

    /**
     * One entry of challenge_peak_records[].
     */
    public static function anomalyArbitrationRecord(
        int $mobStars = 9,
        ?int $bossStars = 3,
        bool $plight = false,
        bool $played = true,
        array $begin = self::ANOMALY_ARBITRATION_BEGIN,
        array $end = self::ANOMALY_ARBITRATION_END,
    ): array {
        return [
            'group' => [
                'group_id' => 7,
                'begin_time' => $begin,
                'end_time' => $end,
                // The current run says "End": the status cannot be trusted
                'status' => 'End',
                'name_mi18n' => 'Example Stage',
                'game_version' => '4.7',
                'theme_pic_path' => 'https://example.com/theme.png',
            ],
            'boss_info' => [
                'maze_id' => 9104,
                'name_mi18n' => 'Example King',
                'hard_mode_name_mi18n' => 'Example King: Plight',
                'icon' => 'https://example.com/boss.png',
            ],
            'mob_infos' => [['maze_id' => 9101, 'name' => 'Knight (I)', 'monster_name' => 'Example Knight', 'monster_icon' => 'https://example.com/knight.png']],
            'has_challenge_record' => $played,
            'battle_num' => $played ? 8 : 0,
            'boss_record' => $bossStars === null ? null : [
                'maze_id' => 0,
                'has_challenge_record' => true,
                'challenge_time' => ['year' => 2026, 'month' => 11, 'day' => 16, 'hour' => 18, 'minute' => 27],
                'avatars' => [self::avatar(1104)],
                'hard_mode' => $plight,
                'round_num' => 3,
                'star_num' => $bossStars,
                'finish_color_medal' => false,
                'challenge_peak_rank_icon_type' => 'ChallengePeakRankIconTypeSilver',
                'challenge_peak_rank_icon' => 'https://example.com/rank.png',
                'record_unique_key' => '',
            ],
            'mob_records' => $played ? [[
                'maze_id' => 9101,
                'has_challenge_record' => true,
                'challenge_time' => ['year' => 2026, 'month' => 11, 'day' => 16, 'hour' => 18, 'minute' => 13],
                'avatars' => [self::avatar(1105)],
                'round_num' => 2,
                'star_num' => 3,
                'is_fast' => false,
            ]] : [],
            'boss_stars' => $bossStars ?? 0,
            'mob_stars' => $mobStars,
        ];
    }

    private static function node(int $avatarId, int $minute, bool $withoutAvatars = false): array
    {
        return [
            'challenge_time' => ['year' => 2026, 'month' => 11, 'day' => 2, 'hour' => 20, 'minute' => 30 + $minute],
            'avatars' => $withoutAvatars ? [] : [self::avatar($avatarId)],
        ];
    }

    private static function avatar(int $id): array
    {
        return [
            'id' => $id,
            'level' => 80,
            'icon' => 'https://example.com/avatar.png',
            'rarity' => 5,
            'element' => 'wind',
            'rank' => 0,
            'is_return_assist' => false,
        ];
    }
}
