<?php

namespace App\Tests\HoyoverseBundle\Service\Endgame;

/**
 * HoYoLAB response bodies of the Genshin Impact endgame records, with the structure of real responses (fields not
 * read by the DTOs included) and invented values. Periods in server time of an EU profile (UTC+01:00).
 */
final class GenshinEndgameFixture
{
    /** Spiral Abyss period: 2026-03-16 04:00:00 → 2026-04-16 03:59:59 */
    public const string SPIRAL_ABYSS_START = '1773630000';
    public const string SPIRAL_ABYSS_END = '1776308399';

    /** Imaginarium Theater period: 2026-03-01 04:00:00 → 2026-04-01 03:59:59; the previous one from 2026-02-01 04:00:00 */
    public const string THEATER_START = '1772334000';
    public const string THEATER_END = '1775012399';
    public const string THEATER_PREVIOUS_START = '1769914800';

    /** Stygian Onslaught period: 2026-02-25 10:00:00 → 2026-03-31 03:59:59 */
    public const string STYGIAN_START = '1772010000';
    public const string STYGIAN_END = '1774925999';

    /** Previous Stygian Onslaught period: 2026-01-14 10:00:00 → 2026-02-17 03:59:59, then a gap until 2026-02-25 */
    public const string STYGIAN_PREVIOUS_START = '1768381200';
    public const string STYGIAN_PREVIOUS_END = '1771297199';

    /**
     * Body of game_record/genshin/api/spiralAbyss (schedule_type=1).
     *
     * @param list<array{0: int, 1: int}> $floors     floor number and stars of each floor played (only those are listed)
     * @param int                         $battles    total_battle_times
     * @param int|null                    $totalStars total_star, null = the sum over the floors
     */
    public static function spiralAbyss(array $floors = [[11, 9], [12, 9]], int $battles = 8, ?int $totalStars = 36, bool $unlocked = true): array
    {
        $floorEntries = [];
        foreach ($floors as [$index, $stars]) {
            $floorEntries[] = self::abyssFloor($index, $stars);
        }

        return [
            'retcode' => 0,
            'message' => 'OK',
            'data' => [
                'schedule_id' => 99,
                'start_time' => self::SPIRAL_ABYSS_START,
                'end_time' => self::SPIRAL_ABYSS_END,
                'total_battle_times' => $battles,
                'total_win_times' => $battles,
                'max_floor' => $floors !== [] ? sprintf('%d-3', $floors[array_key_last($floors)][0]) : '0-0',
                'reveal_rank' => $battles > 0 ? [self::rank(10000001, $battles)] : [],
                'defeat_rank' => $battles > 0 ? [self::rank(10000002, 12)] : [],
                'damage_rank' => $battles > 0 ? [self::rank(10000003, 123456)] : [],
                'take_damage_rank' => $battles > 0 ? [self::rank(10000004, 23456)] : [],
                'normal_skill_rank' => $battles > 0 ? [self::rank(10000005, 21)] : [],
                'energy_skill_rank' => $battles > 0 ? [self::rank(10000003, 8)] : [],
                'floors' => $floorEntries,
                'total_star' => $totalStars ?? array_sum(array_column($floors, 1)),
                'is_unlock' => $unlocked,
                'is_just_skipped_floor' => false,
                'skipped_floor' => '',
            ],
        ];
    }

    /**
     * Spiral Abyss of a period just started: floors 9 and 10 skipped (18 stars credited), no battle yet.
     */
    public static function spiralAbyssWithSkippedFloors(): array
    {
        $body = self::spiralAbyss([], 0, 18);
        $body['data']['max_floor'] = '10-3';
        $body['data']['is_just_skipped_floor'] = true;
        $body['data']['skipped_floor'] = '10-3';

        return $body;
    }

    /**
     * Spiral Abyss of an account that has not unlocked it: dates present, everything else empty.
     */
    public static function spiralAbyssLocked(): array
    {
        return self::spiralAbyss([], 0, 0, unlocked: false);
    }

    /**
     * Body of game_record/genshin/api/role_combat (need_detail=false): the current period, then the previous one.
     *
     * @param array{0: int, 1: int, 2: int, 3: list<int>}|null $current  difficulty_id, max_round_id, tarot_finished_cnt
     *                                                                   and get_medal_round_list; null = not played
     * @param array{0: int, 1: int, 2: int, 3: list<int>}|null $previous same for the previous period
     */
    public static function theater(?array $current = null, ?array $previous = [5, 10, 2, [1, 0, 1, 1, 1, 1, 0, 1, 1, 1, 1, 0]], bool $unlocked = true): array
    {
        return [
            'retcode' => 0,
            'message' => 'OK',
            'data' => [
                'data' => [
                    self::theaterPeriod($current, 1, 41, self::THEATER_START, self::THEATER_END),
                    self::theaterPeriod($previous, 2, 40, self::THEATER_PREVIOUS_START, (string) ((int) self::THEATER_START - 1)),
                ],
                'is_unlock' => $unlocked,
                'links' => ['lineup_link' => 'https://example.com/lineup', 'lineup_link_pc' => '', 'strategy_link' => '', 'lineup_publish_link' => '', 'lineup_publish_link_pc' => ''],
                'tarot_card_state' => [
                    'total_num' => 22,
                    'curr_num' => 1,
                    'list' => [['icon' => '', 'name' => 'Example Arcana', 'is_unlock' => true, 'unlock_num' => 1]],
                ],
            ],
        ];
    }

    /**
     * Body of game_record/genshin/api/hard_challenge: the current period, then the previous one.
     *
     * @param list<int>|null $single best of the current period solo: difficulty and seconds; [] = a record without a
     *                               best; null = no record
     * @param list<int>|null $mp     same in co-op
     */
    public static function stygianOnslaught(?array $single = [4, 197], ?array $mp = null, bool $unlocked = true): array
    {
        return [
            'retcode' => 0,
            'message' => 'OK',
            'data' => [
                'data' => [
                    self::stygianPeriod('9900002', 'Example Onslaught', self::STYGIAN_START, self::STYGIAN_END, $single, $mp),
                    self::stygianPeriod('9900001', 'Earlier Example Onslaught', self::STYGIAN_PREVIOUS_START, self::STYGIAN_PREVIOUS_END, [5, 241], null),
                ],
                'is_unlock' => $unlocked,
                'links' => ['lineup_link' => '', 'play_link' => ''],
            ],
        ];
    }

    private static function abyssFloor(int $index, int $stars): array
    {
        $chambers = [];
        for ($chamber = 1; $chamber <= 3; $chamber++) {
            $chamberStars = max(0, min(3, $stars - 3 * ($chamber - 1)));
            $chambers[] = [
                'index' => $chamber,
                'star' => $chamberStars,
                'max_star' => 3,
                'battles' => [
                    self::abyssBattle(1, $chamber),
                    self::abyssBattle(2, $chamber),
                ],
                'top_half_floor_monster' => [['name' => 'Example Monster', 'icon' => 'https://example.com/monster.png', 'level' => 95]],
                'bottom_half_floor_monster' => [['name' => 'Other Example Monster', 'icon' => 'https://example.com/monster.png', 'level' => 95]],
            ];
        }

        return [
            'index' => $index,
            'icon' => '',
            'is_unlock' => true,
            'settle_time' => '0',
            'star' => $stars,
            'max_star' => 9,
            'levels' => $chambers,
            'settle_date_time' => null,
            'ley_line_disorder' => ['Example ley line disorder.'],
            'ley_line_disorder_upper' => [],
            'ley_line_disorder_lower' => [],
        ];
    }

    private static function abyssBattle(int $half, int $chamber): array
    {
        return [
            'index' => $half,
            'timestamp' => (string) ((int) self::SPIRAL_ABYSS_START + 86400 + 60 * (2 * $chamber + $half)),
            'avatars' => [
                ['id' => 10000001, 'icon' => 'https://example.com/avatar.png', 'level' => 90, 'rarity' => 5],
                ['id' => 10000002, 'icon' => 'https://example.com/avatar.png', 'level' => 90, 'rarity' => 4],
            ],
            'settle_date_time' => ['year' => 2026, 'month' => 3, 'day' => 17, 'hour' => 20, 'minute' => 2 * $chamber + $half, 'second' => 0],
        ];
    }

    private static function rank(int $avatarId, int $value): array
    {
        return ['avatar_id' => $avatarId, 'avatar_icon' => 'https://example.com/avatar.png', 'value' => $value, 'rarity' => 5];
    }

    /**
     * @param array{0: int, 1: int, 2: int, 3: list<int>}|null $stat
     */
    private static function theaterPeriod(?array $stat, int $scheduleType, int $scheduleId, string $start, string $end): array
    {
        [$difficulty, $acts, $arcana, $medals] = $stat ?? [0, 0, 0, []];

        return [
            'detail' => null,
            'stat' => [
                'difficulty_id' => $difficulty,
                'max_round_id' => $acts,
                'heraldry' => $difficulty,
                'get_medal_round_list' => $medals,
                'medal_num' => array_sum($medals),
                'coin_num' => $stat !== null ? 1200 : 0,
                'avatar_bonus_num' => $stat !== null ? 1 : 0,
                'rent_cnt' => $stat !== null ? 2 : 0,
                'tarot_finished_cnt' => $arcana,
            ],
            'schedule' => [
                'start_time' => $start,
                'end_time' => $end,
                'schedule_type' => $scheduleType,
                'schedule_id' => $scheduleId,
                'start_date_time' => self::dateObject((int) $start),
                'end_date_time' => self::dateObject((int) $end),
            ],
            'has_data' => $stat !== null,
            'has_detail_data' => $stat !== null,
        ];
    }

    /**
     * @param list<int>|null $single
     * @param list<int>|null $mp
     */
    private static function stygianPeriod(string $scheduleId, string $name, string $start, string $end, ?array $single, ?array $mp): array
    {
        return [
            'schedule' => [
                'schedule_id' => $scheduleId,
                'start_time' => $start,
                'end_time' => $end,
                'start_date_time' => self::dateObject((int) $start),
                'end_date_time' => self::dateObject((int) $end),
                'is_valid' => true,
                'name' => $name,
            ],
            'single' => self::stygianRecord($single),
            'mp' => self::stygianRecord($mp),
            'blings' => [],
        ];
    }

    /**
     * @param list<int>|null $best difficulty and seconds; [] = a record without a best; null = no record
     */
    private static function stygianRecord(?array $best): array
    {
        if ($best === null) {
            return ['best' => null, 'challenge' => [], 'has_data' => false];
        }

        $challenges = [];
        foreach ([0.4, 0.35, 0.25] as $index => $share) {
            $challenges[] = [
                'name' => sprintf('Example Boss %d', $index + 1),
                'second' => $best !== [] ? (int) round($best[1] * $share) : 0,
                'teams' => [],
                'best_avatar' => [['avatar_id' => 10000001, 'side_icon' => 'https://example.com/side.png', 'dps' => '123456', 'type' => 1]],
                'monster' => ['name' => 'Example Boss', 'level' => 100, 'icon' => 'https://example.com/boss.png', 'desc' => ['Example description.'], 'tags' => [], 'monster_id' => 9900101 + $index],
            ];
        }

        return [
            'best' => $best !== [] ? ['difficulty' => $best[0], 'second' => $best[1], 'icon' => sprintf('UI_LeyLineChallenge_Medal_%d', $best[0])] : null,
            'challenge' => $challenges,
            'has_data' => true,
        ];
    }

    /**
     * Server wall-clock date object of an epoch (EU server, UTC+01:00).
     */
    private static function dateObject(int $epoch): array
    {
        $date = new \DateTimeImmutable('@' . $epoch)->setTimezone(new \DateTimeZone('+01:00'));

        return [
            'year' => (int) $date->format('Y'),
            'month' => (int) $date->format('n'),
            'day' => (int) $date->format('j'),
            'hour' => (int) $date->format('G'),
            'minute' => (int) $date->format('i'),
            'second' => (int) $date->format('s'),
        ];
    }
}
