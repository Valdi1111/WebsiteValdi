<?php

namespace App\Tests\HoyoverseBundle\Service\Endgame;

/**
 * HoYoLAB response bodies of the Zenless Zone Zero endgame records, with the structure of real responses
 * (fields not read by the DTOs included) and invented values.
 */
final class ZenlessEndgameFixture
{
    /** Shiyu Defense period: 2026-03-06 04:00:00 → 2026-03-20 03:59:59 server time of an EU profile (UTC+01:00) */
    public const string SHIYU_BEGIN_TIMESTAMP = '1772766000';
    public const string SHIYU_END_TIMESTAMP = '1773975599';

    /** Deadly Assault period, server time: 2026-03-13 04:00:00 → 2026-03-25 03:59:59 */
    public const array DEADLY_ASSAULT_START = ['year' => 2026, 'month' => 3, 'day' => 13, 'hour' => 4, 'minute' => 0, 'second' => 0];
    public const array DEADLY_ASSAULT_END = ['year' => 2026, 'month' => 3, 'day' => 25, 'hour' => 3, 'minute' => 59, 'second' => 59];

    /**
     * Body of game_record_zzz/api/zzz/hadal_info_v2 (Shiyu Defense) requested with the per-stage detail.
     *
     * @param list<array{0: int, 1: string}> $stages            score and rating of each fifth-floor stage
     * @param string|null                    $fourthFloorRating rating of the fourth floor, null = not attempted
     */
    public static function shiyuDefense(array $stages = [[31250, 'S'], [42010, 'S'], [27480, 'S']], ?string $fourthFloorRating = 'S'): array
    {
        $layers = [];
        foreach ($stages as $index => [$score, $rating]) {
            $layers[] = [
                'layer_id' => 61999051 + $index,
                'rating' => $rating,
                'buffer' => ['title' => 'Example Buff', 'text' => '· Example buff description.'],
                'score' => $score,
                'avatar_list' => [
                    self::agent(1011),
                    self::agent(1021),
                    self::agent(1031),
                ],
                'buddy' => ['id' => 50001, 'rarity' => 'A', 'level' => 50, 'bangboo_rectangle_url' => 'https://example.com/bangboo.png'],
                'monster_pic' => 'https://example.com/monster.png',
                'max_score' => 50000,
                'challenge_time' => ['year' => 2026, 'month' => 3, 'day' => 8, 'hour' => 18, 'minute' => 10 + $index, 'second' => 0],
            ];
        }

        $total = array_sum(array_column($stages, 0));

        return [
            'retcode' => 0,
            'message' => 'OK',
            'data' => [
                'hadal_ver' => 'v2',
                'hadal_info_v2' => [
                    'zone_id' => 61999,
                    'hadal_begin_time' => ['year' => 2026, 'month' => 3, 'day' => 6, 'hour' => 4, 'minute' => 0, 'second' => 0],
                    'hadal_end_time' => ['year' => 2026, 'month' => 3, 'day' => 20, 'hour' => 3, 'minute' => 59, 'second' => 59],
                    'pass_fifth_floor' => $layers !== [],
                    'brief' => [
                        'cur_period_zone_layer_count' => 5,
                        'score' => $total,
                        'rank_percent' => 1234,
                        'rating' => 'S',
                        'max_score' => 150000,
                    ],
                    'fitfh_layer_detail' => ['layer_challenge_info_list' => $layers],
                    // Rated without a score, like in the real responses
                    'fourth_layer_detail' => $fourthFloorRating === null ? null : [
                        'buffer' => ['title' => 'Example Buff', 'text' => '· Example buff description.'],
                        'challenge_time' => ['year' => 2026, 'month' => 3, 'day' => 8, 'hour' => 18, 'minute' => 0, 'second' => 0],
                        'rating' => $fourthFloorRating,
                        'layer_challenge_info_list' => [
                            ['layer_id' => 61999041, 'avatar_list' => [self::agent(1011)], 'buddy' => ['id' => 50001, 'rarity' => 'A', 'level' => 50, 'bangboo_rectangle_url' => 'https://example.com/bangboo.png'], 'challenge_time' => ['year' => 2026, 'month' => 3, 'day' => 8, 'hour' => 18, 'minute' => 0, 'second' => 0]],
                        ],
                    ],
                    'begin_time' => self::SHIYU_BEGIN_TIMESTAMP,
                    'end_time' => self::SHIYU_END_TIMESTAMP,
                ],
                'nick_name' => 'Proxy',
                'icon' => 'https://example.com/icon.png',
            ],
        ];
    }

    /**
     * Body of game_record_zzz/api/zzz/hadal_mem_detail_v2 (Deadly Assault).
     *
     * @param list<array{0: int, 1: int, 2: string}> $bosses score, stars and boss name of the normal mode
     * @param list<array{0: int, 1: int, 2: string}> $hard    score, stars and boss name of the Hard mode
     * @param bool                                   $hasHard Hard mode unlocked (has_hard)
     */
    public static function deadlyAssault(
        array $bosses = [[25120, 3, 'Example Boss A'], [31870, 3, 'Example Boss B'], [44300, 3, 'Example Boss C']],
        array $hard = [[30410, 3, 'Example Hard Boss']],
        bool $hasHard = true,
    ): array {
        return [
            'retcode' => 0,
            'message' => 'OK',
            'data' => [
                'start_time' => self::DEADLY_ASSAULT_START,
                'end_time' => self::DEADLY_ASSAULT_END,
                'rank_percent' => 2345,
                'list' => array_map(self::challenge(...), $bosses),
                'has_data' => true,
                'nick_name' => 'Proxy',
                'avatar_icon' => 'https://example.com/icon.png',
                'total_score' => array_sum(array_column($bosses, 0)),
                'total_star' => array_sum(array_column($bosses, 1)),
                'zone_id' => 699901,
                'total_max_score' => 195000,
                'room_max_score' => 65000,
                'has_hard' => $hasHard,
                'hard_list' => array_map(self::challenge(...), $hard),
                'hard_rank_percent' => 3456,
            ],
        ];
    }

    /**
     * @param array{0: int, 1: int, 2: string} $boss
     */
    private static function challenge(array $boss): array
    {
        [$score, $stars, $name] = $boss;

        return [
            'score' => $score,
            'star' => $stars,
            'total_star' => 3,
            'challenge_time' => ['year' => 2026, 'month' => 3, 'day' => 14, 'hour' => 21, 'minute' => 0, 'second' => 0],
            'boss' => [[
                'icon' => 'https://example.com/boss.png',
                'name' => $name,
                'race_icon' => 'https://example.com/race.png',
                'bg_icon' => 'https://example.com/bg.png',
            ]],
            'buffer' => [['icon' => 'https://example.com/buff.png', 'desc' => '· Example buff description.', 'name' => 'Example Buff']],
            'avatar_list' => [self::agent(1011), self::agent(1021), self::agent(1031)],
            'buddy' => ['id' => 50001, 'rarity' => 'A', 'level' => 50, 'bangboo_rectangle_url' => 'https://example.com/bangboo.png'],
        ];
    }

    private static function agent(int $id): array
    {
        return [
            'id' => $id,
            'level' => 60,
            'rarity' => 'S',
            'element_type' => 200,
            'avatar_profession' => 1,
            'rank' => 0,
            'role_square_url' => 'https://example.com/agent.png',
            'sub_element_type' => 0,
        ];
    }
}
