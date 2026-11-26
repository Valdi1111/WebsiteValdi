<?php

namespace App\HoyoverseBundle\Service\Pull;

use App\CoreBundle\Doctrine\WallClockDateTimeImmutableType;
use App\HoyoverseBundle\Service\Catalog\AbstractIrminsulCatalog;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Rate-up flags and banner phases of the pulls of one HoYoverse profile, read from the game's irminsul.gg catalog
 * (GiCatalog, HsrCatalog, ZzzCatalog). Built per profile by HoyoverseRateUpLookupFactory.
 *
 * Dates: a stored pulled_at is the wall clock of the player's server, whatever timezone it is tagged with, so only
 * its digits are compared. A phase time with an offset is converted to the server wall clock; one without is
 * already the local time of the server.
 *
 * Only the limited character and weapon banners have phases: every other gacha type (standard, beginner,
 * Chronicled Wish, HSR collaboration warps, Bangboo channel) has no category, and its rows stay unknown.
 *
 * @phpstan-type Phase array{id: int, category: string, version: string, title: ?string, start: array{at: string, offset: ?int}, end: array{at: string, offset: ?int}, up: list<string>, up_a: list<string>}
 */
#[Exclude]
class HoyoverseRateUpLookup
{
    public const string GAME_GI = 'gi';
    public const string GAME_HSR = 'hsr';
    public const string GAME_ZZZ = 'zzz';

    private const string CHARACTER = AbstractIrminsulCatalog::CATEGORY_CHARACTER;
    private const string WEAPON = AbstractIrminsulCatalog::CATEGORY_WEAPON;

    /** Catalog category of the limited banners of each game, by gacha type */
    private const array CATEGORY_BY_GACHA_TYPE = [
        self::GAME_GI => ['301' => self::CHARACTER, '400' => self::CHARACTER, '302' => self::WEAPON],
        self::GAME_HSR => ['11' => self::CHARACTER, '12' => self::WEAPON],
        self::GAME_ZZZ => ['2' => self::CHARACTER, '12' => self::CHARACTER, '3' => self::WEAPON, '13' => self::WEAPON],
    ];

    /** Item type of the pull records of each game, by catalog category (read by the icon resolvers) */
    private const array RECORD_ITEM_TYPES = [
        self::GAME_GI => [self::CHARACTER => 'Character', self::WEAPON => 'Weapon'],
        self::GAME_HSR => [self::CHARACTER => 'Character', self::WEAPON => 'Light Cone'],
        self::GAME_ZZZ => [self::CHARACTER => 'Agents', self::WEAPON => 'W-Engines'],
    ];

    /** ZZZ rerun banners: Exclusive Rescreening (agents) and W-Engine Reverberation */
    private const array ZZZ_RERUN_GACHA_TYPES = ['12', '13'];

    /** Since this ZZZ version the player chooses the A rate-ups, so the catalog lists cannot tell them */
    private const array ZZZ_CHOSEN_A_SINCE = [3, 1];

    private const string WALL_CLOCK_FORMAT = 'Y-m-d H:i:s';

    /**
     * Phases of each category sorted by start (server wall clock), with their converted limits and the running
     * maximum of the ends, so that the active phases of a time are found without scanning the whole list.
     *
     * @var array<string, array{phases: list<Phase>, starts: list<string>, ends: list<string>, max_ends: list<string>}>
     */
    private array $indexes = [];

    /**
     * Active phase positions of the last time looked up per category: rows come oldest first, a ten-pull shares its
     * second, and the calculator asks for the flag and the phase of the same row.
     *
     * @var array<string, array{at: string, positions: list<int>}>
     */
    private array $lastActive = [];

    public function __construct(
        private readonly AbstractIrminsulCatalog $catalog,
        private readonly \DateTimeZone $serverZone,
        private readonly string $game,
    ) {
        if (!isset(self::CATEGORY_BY_GACHA_TYPE[$game])) {
            throw new \InvalidArgumentException(sprintf('Unknown HoYoverse game key "%s".', $game));
        }
    }

    /**
     * Catalog category of a gacha type ("character" or "weapon"), null for banners without phases.
     */
    public function categoryFor(string $gachaType): ?string
    {
        return self::CATEGORY_BY_GACHA_TYPE[$this->game][trim($gachaType)] ?? null;
    }

    /**
     * Phases of the gacha type's category running at the given server wall-clock time, limits included, sorted by
     * start. Several when phases overlap (concurrent banners, a rerun running beside a new banner).
     *
     * @return list<Phase>
     */
    public function activePhases(string $gachaType, \DateTimeInterface $pulledAt): array
    {
        $category = $this->categoryFor($gachaType);
        if ($category === null) {
            return [];
        }

        $phases = $this->indexOf($category)['phases'];

        return array_map(static fn (int $position): array => $phases[$position], $this->activePositions($category, $pulledAt));
    }

    /**
     * Rate-up flag of a repository row (gachaType, itemId, name, pulledAt, isUp): the stored value when a source
     * gave one, else what the catalog says. Null means unknown, never a lost 50/50.
     *
     * An S is up when it is an S rate-up of a running phase. An A is up when it is an A rate-up of a running phase,
     * except where the catalog cannot tell: ZZZ phases since 3.1 (the player picks the A rate-ups) and A light cones
     * of the HSR light cone warp (irminsul's A light cone lists are incomplete), where an A missing from the lists
     * stays unknown.
     *
     * @param array<string, mixed> $row
     */
    public function isUp(array $row, bool $isS, bool $isA): ?bool
    {
        if (isset($row['isUp'])) {
            return (bool) $row['isUp'];
        }
        if (!$isS && !$isA) {
            return null;
        }

        $gachaType = (string) ($row['gachaType'] ?? '');
        $category = $this->categoryFor($gachaType);
        $pulledAt = self::pulledAtOf($row);
        if ($category === null || $pulledAt === null) {
            return null;
        }

        $phases = $this->activePhases($gachaType, $pulledAt);
        if ($phases === []) {
            return null;
        }

        $item = $this->findItemOf($row);
        if ($item === null) {
            return null;
        }

        if ($isS) {
            return \in_array($item['id'], self::union($phases, 'up'), true);
        }

        if ($this->game === self::GAME_ZZZ) {
            foreach ($phases as $phase) {
                if (self::isVersionAtLeast($phase['version'], self::ZZZ_CHOSEN_A_SINCE)) {
                    return null;
                }
            }
        }
        if (\in_array($item['id'], self::union($phases, 'up_a'), true)) {
            return true;
        }
        // Light cones only: an A character on the light cone warp is never featured there, so it is a definite miss
        if ($this->game === self::GAME_HSR && $category === self::WEAPON && $item['category'] === self::WEAPON) {
            return null;
        }

        return false;
    }

    /**
     * The phase a pull belongs to in the per-phase breakdown: the running phase that started last, the lowest
     * phase id on equal starts (concurrent banners opening together). On ZZZ the rerun banners (Exclusive
     * Rescreening, W-Engine Reverberation) prefer the rerun phases and the other banners the others (see
     * isRerunPhase()): 3.1.2 and its rescreening 3.1.3 open at the same second.
     * Null outside every phase.
     *
     * @return Phase|null
     */
    public function phaseFor(string $gachaType, \DateTimeInterface $pulledAt): ?array
    {
        $category = $this->categoryFor($gachaType);
        if ($category === null) {
            return null;
        }

        $positions = $this->activePositions($category, $pulledAt);
        if ($positions === []) {
            return null;
        }

        $index = $this->indexOf($category);
        $rerunBanner = $this->game === self::GAME_ZZZ && \in_array(trim($gachaType), self::ZZZ_RERUN_GACHA_TYPES, true);
        $best = null;
        $bestKey = null;
        foreach ($positions as $position) {
            $phase = $index['phases'][$position];
            $sameKind = $this->game !== self::GAME_ZZZ || ($this->isRerunPhase($phase) === $rerunBanner);
            $key = [$sameKind ? 1 : 0, $index['starts'][$position], -$phase['id']];
            if ($bestKey === null || $key > $bestKey) {
                $best = $position;
                $bestKey = $key;
            }
        }

        return $index['phases'][$best];
    }

    /**
     * Whether a ZZZ phase is a rerun (Exclusive Rescreening, W-Engine Reverberation): irminsul titles only some of
     * them (3.1.3), so a phase whose S rate-ups were all released before its version (2.5.2: Alice 2.1, Soldier 0
     * Anby 1.6, Astra 1.5) is one too. Phases without A rate-ups are not enough: irminsul leaves out the A of new
     * banners too (3.4.1 Sunbringer and Robin, both 3.4).
     *
     * @param Phase $phase
     */
    private function isRerunPhase(array $phase): bool
    {
        if ($phase['title'] !== null) {
            return true;
        }

        $phaseVersion = self::majorMinorOf($phase['version']);
        if ($phaseVersion === null || $phase['up'] === []) {
            return false;
        }
        foreach ($phase['up'] as $id) {
            $release = self::majorMinorOf((string) ($this->catalog->findItem($id)['release'] ?? ''));
            if ($release === null || $release >= $phaseVersion) {
                return false;
            }
        }

        return true;
    }

    /**
     * "3.4.2" or "3.4" => [3, 4]; null for other formats.
     *
     * @return array{int, int}|null
     */
    private static function majorMinorOf(string $version): ?array
    {
        return preg_match('/^(\d+)\.(\d+)/', trim($version), $matches) ? [(int) $matches[1], (int) $matches[2]] : null;
    }

    /**
     * Display name of a phase: "<version> · <title> · <S rate-ups>", e.g. "3.1.3 · Exclusive Rescreening · Dialyn,
     * Yuzuha, Harumasa" (the title only when the catalog has one). Names follow the pull records of each game:
     * the full display name on Genshin and HSR ("Yae Miko", "Aeon ★ Aha"), the short name on ZZZ ("Yuzuha").
     *
     * @param Phase $phase
     */
    public function phaseLabel(array $phase): string
    {
        return implode(' · ', array_filter(
            [$phase['version'], (string) $phase['title'], implode(', ', array_column($this->phaseFeatured($phase), 'name'))],
            static fn (string $part): bool => $part !== '',
        ));
    }

    /**
     * S rate-up items of a phase as pull-row-like entries, so the shared icon pipeline resolves them like the
     * pulls: the item id on HSR and ZZZ (irminsul ids are the game ids), none on Genshin (its records carry no id
     * and its icons are matched by name), the name as in the records and the item type of the game's records.
     *
     * @param Phase $phase
     *
     * @return list<array{item_id: ?string, name: string, item_type: string, rarity: int}>
     */
    public function phaseFeatured(array $phase): array
    {
        $featured = [];
        foreach ($phase['up'] as $id) {
            $item = $this->catalog->findItem($id);
            if ($item === null) {
                continue;
            }
            $featured[] = [
                'item_id' => $this->game === self::GAME_GI ? null : $item['id'],
                'name' => $this->game === self::GAME_ZZZ ? $item['name'] : ($item['display_name'] ?: $item['name']),
                'item_type' => self::RECORD_ITEM_TYPES[$this->game][$item['category']],
                'rarity' => $this->game === self::GAME_ZZZ ? 4 : 5,
            ];
        }

        return $featured;
    }

    /**
     * Limits of a phase as the server wall clock of the profile.
     *
     * @param Phase $phase
     *
     * @return array{start_at: string, end_at: string}
     */
    public function phaseWindow(array $phase): array
    {
        return [
            'start_at' => $this->toServerWallClock($phase['start']),
            'end_at' => $this->toServerWallClock($phase['end']),
        ];
    }

    /**
     * Index positions of the phases of a category running at a server wall-clock time, limits included, in index order.
     *
     * @return list<int>
     */
    private function activePositions(string $category, \DateTimeInterface $pulledAt): array
    {
        $at = $pulledAt->format(self::WALL_CLOCK_FORMAT);
        if (($this->lastActive[$category]['at'] ?? null) === $at) {
            return $this->lastActive[$category]['positions'];
        }

        $index = $this->indexOf($category);

        // Number of phases starting at or before the time (binary search on the sorted starts)
        $low = 0;
        $high = count($index['starts']);
        while ($low < $high) {
            $middle = intdiv($low + $high, 2);
            if ($index['starts'][$middle] <= $at) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }

        // Walk back while an earlier phase can still be running (running maximum of the ends)
        $positions = [];
        for ($i = $low - 1; $i >= 0 && $index['max_ends'][$i] >= $at; --$i) {
            if ($index['ends'][$i] >= $at) {
                $positions[] = $i;
            }
        }
        $positions = array_reverse($positions);

        $this->lastActive[$category] = ['at' => $at, 'positions' => $positions];

        return $positions;
    }

    /**
     * @param array{at: string, offset: ?int} $time
     */
    private function toServerWallClock(array $time): string
    {
        if ($time['offset'] === null) {
            return $time['at'];
        }

        $instant = new \DateTimeImmutable($time['at'], new \DateTimeZone(sprintf('%+03d:00', $time['offset'])));

        return $instant->setTimezone($this->serverZone)->format(self::WALL_CLOCK_FORMAT);
    }

    /**
     * @return array{phases: list<Phase>, starts: list<string>, ends: list<string>, max_ends: list<string>}
     */
    private function indexOf(string $category): array
    {
        if (isset($this->indexes[$category])) {
            return $this->indexes[$category];
        }

        $entries = [];
        foreach ($this->catalog->getPhases($category) as $phase) {
            try {
                $entries[] = ['phase' => $phase, 'start' => $this->toServerWallClock($phase['start']), 'end' => $this->toServerWallClock($phase['end'])];
            } catch (\Exception) {
                // An offset PHP cannot read as a timezone: the phase is left out
                continue;
            }
        }
        usort($entries, static fn (array $a, array $b): int => [$a['start'], $a['phase']['id']] <=> [$b['start'], $b['phase']['id']]);

        $index = ['phases' => [], 'starts' => [], 'ends' => [], 'max_ends' => []];
        $maxEnd = '';
        foreach ($entries as $entry) {
            if ($entry['end'] > $maxEnd) {
                $maxEnd = $entry['end'];
            }
            $index['phases'][] = $entry['phase'];
            $index['starts'][] = $entry['start'];
            $index['ends'][] = $entry['end'];
            $index['max_ends'][] = $maxEnd;
        }

        return $this->indexes[$category] = $index;
    }

    /**
     * Catalog item of a row: by item id when it is a catalog id (HSR, ZZZ), else by name (Genshin rows have none).
     *
     * @param array<string, mixed> $row
     *
     * @return array{id: string, name: string, display_name: ?string, rarity: ?int, category: string}|null
     */
    private function findItemOf(array $row): ?array
    {
        $itemId = trim((string) ($row['itemId'] ?? ''));
        if ($itemId !== '' && ($item = $this->catalog->findItem($itemId)) !== null) {
            return $item;
        }

        return $this->catalog->findItemByName((string) ($row['name'] ?? ''));
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function pulledAtOf(array $row): ?\DateTimeInterface
    {
        $pulledAt = $row['pulledAt'] ?? null;
        if ($pulledAt instanceof \DateTimeInterface) {
            return $pulledAt;
        }
        if (!\is_string($pulledAt)) {
            return null;
        }

        try {
            return WallClockDateTimeImmutableType::fromString(trim($pulledAt));
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Item ids of a rate-up list ("up" or "up_a") across the given phases.
     *
     * @param list<Phase> $phases
     *
     * @return list<string>
     */
    private static function union(array $phases, string $list): array
    {
        return array_merge(...array_map(static fn (array $phase): array => $phase[$list], $phases));
    }

    /**
     * Whether a "major.minor[.phase]" version is at least the given [major, minor]; false when it has no such form.
     *
     * @param array{int, int} $minimum
     */
    private static function isVersionAtLeast(string $version, array $minimum): bool
    {
        if (!preg_match('/^(\d+)\.(\d+)/', trim($version), $matches)) {
            return false;
        }

        return [(int) $matches[1], (int) $matches[2]] >= $minimum;
    }
}
