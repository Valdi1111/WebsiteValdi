<?php

namespace App\HoyoverseBundle\Service\Catalog;

use App\GamesBundle\Service\Irminsul\IrminsulClient;
use App\GamesBundle\Service\Irminsul\IrminsulException;
use App\GamesBundle\Service\Irminsul\IrminsulPhaseNormalizer;
use App\GamesBundle\Service\Irminsul\IrminsulTimeRules;
use Psr\Log\LoggerInterface;

/**
 * Builds the banner catalog of a HoYoverse game (shape documented on AbstractIrminsulCatalog) from the irminsul.gg
 * data API: the items come from characters.json and weapons.json, keyed by irminsul id; the phases from
 * banner-characters.json and banner-weapons.json, normalized with the time rules of the game.
 *
 * The "rateUps" of a phase name the S and A items of every concurrent banner, by the exact irminsul "name": they are
 * resolved against the items of the phase category, rarity 5 going to "up" and 4 to "up_a". An unknown or ambiguous
 * name logs a warning (once per name) and is skipped.
 *
 * Any unreadable file, or a category left without items or phases, throws before anything is saved, so the previous
 * catalog stays in place. The result is not merged with the previous catalog: irminsul serves the whole history.
 */
abstract class AbstractIrminsulCatalogBuilder
{
    /** Item file and banner file of each catalog category */
    private const array FILES = [
        AbstractIrminsulCatalog::CATEGORY_CHARACTER => ['items' => IrminsulClient::FILE_CHARACTERS, 'banners' => IrminsulClient::FILE_BANNER_CHARACTERS],
        AbstractIrminsulCatalog::CATEGORY_WEAPON => ['items' => IrminsulClient::FILE_WEAPONS, 'banners' => IrminsulClient::FILE_BANNER_WEAPONS],
    ];

    private const int RARITY_S = 5;
    private const int RARITY_A = 4;

    public function __construct(
        private readonly IrminsulClient $client,
        private readonly IrminsulPhaseNormalizer $normalizer,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Game name, for the logs and the update command.
     */
    abstract public function getGameName(): string;

    /**
     * Game slug of the irminsul.gg data API.
     */
    abstract protected function getGameSlug(): string;

    abstract protected function getTimeRules(): IrminsulTimeRules;

    /**
     * Builds the catalog. The result is not saved: callers persist it with the catalog's save().
     *
     * @return array{fetched_at: string, items: array<string, array{id: string, name: string, display_name: ?string, rarity: ?int, category: string}>, phases: list<array{id: int, category: string, version: string, title: ?string, start: array{at: string, offset: ?int}, end: array{at: string, offset: ?int}, up: list<string>, up_a: list<string>}>}
     *
     * @throws IrminsulException when a file cannot be read or a category has no item or no phase (the previous catalog must be kept)
     */
    public function build(): array
    {
        $items = [];
        /** @var array<string, array<string, list<string>>> $idsByName category => exact name => item ids */
        $idsByName = [];
        foreach (self::FILES as $category => ['items' => $file]) {
            $count = 0;
            // The decoded file (a few MB) is only referenced by the loop, so it is freed once read
            foreach ($this->client->fetch($this->getGameSlug(), $file) as $entry) {
                $item = self::toItem($entry, $category);
                if ($item === null) {
                    continue;
                }
                if (isset($items[$item['id']])) {
                    $this->logger->warning(sprintf('%s catalog: duplicate irminsul item id skipped', $this->getGameName()), ['id' => $item['id'], 'name' => $item['name'], 'category' => $category]);
                    continue;
                }

                $items[$item['id']] = $item;
                $idsByName[$category][$item['name']][] = $item['id'];
                ++$count;
            }

            if ($count === 0) {
                throw new IrminsulException(sprintf('irminsul.gg %s/%s.json has no usable item.', $this->getGameSlug(), $file));
            }
        }

        $phases = [];
        $phaseCounts = [];
        $unresolved = [];
        foreach (self::FILES as $category => ['banners' => $file]) {
            $normalized = $this->normalizer->normalize($this->client->fetch($this->getGameSlug(), $file), $category, $this->getTimeRules(), $this->logger);
            if ($normalized === []) {
                throw new IrminsulException(sprintf('irminsul.gg %s/%s.json has no usable phase.', $this->getGameSlug(), $file));
            }
            $phaseCounts[$category] = count($normalized);

            foreach ($normalized as $phase) {
                $up = [];
                $upA = [];
                foreach ($phase['rate_ups'] as $name) {
                    $ids = $idsByName[$category][$name] ?? [];
                    if (count($ids) !== 1) {
                        if (!isset($unresolved[$category][$name])) {
                            $unresolved[$category][$name] = true;
                            $this->logger->warning(sprintf('%s catalog: rate-up name skipped, %s', $this->getGameName(), $ids === [] ? 'no item has it' : 'several items have it'), [
                                'name' => $name,
                                'category' => $category,
                                'version' => $phase['version'],
                            ]);
                        }
                        continue;
                    }

                    match ($items[$ids[0]]['rarity']) {
                        self::RARITY_S => $up[] = $ids[0],
                        self::RARITY_A => $upA[] = $ids[0],
                        default => null,
                    };
                }

                $phases[] = [
                    'id' => $phase['id'],
                    'category' => $category,
                    'version' => $phase['version'],
                    'title' => $phase['title'],
                    'start' => $phase['start'],
                    'end' => $phase['end'],
                    'up' => array_values(array_unique($up)),
                    'up_a' => array_values(array_unique($upA)),
                ];
            }
        }

        $this->logger->info(sprintf('%s catalog built', $this->getGameName()), [
            'items' => count($items),
            'character_phases' => $phaseCounts[AbstractIrminsulCatalog::CATEGORY_CHARACTER],
            'weapon_phases' => $phaseCounts[AbstractIrminsulCatalog::CATEGORY_WEAPON],
            'unresolved_names' => array_sum(array_map('count', $unresolved)),
        ]);

        return [
            'fetched_at' => new \DateTimeImmutable()->format(\DateTimeInterface::ATOM),
            'items' => $items,
            'phases' => $phases,
        ];
    }

    /**
     * @param array<string, mixed> $entry
     *
     * @return array{id: string, name: string, display_name: ?string, rarity: ?int, category: string}|null
     */
    private static function toItem(array $entry, string $category): ?array
    {
        $id = $entry['id'] ?? null;
        $name = is_string($entry['name'] ?? null) ? trim($entry['name']) : '';
        if ((!is_int($id) && !(is_string($id) && ctype_digit($id))) || $name === '') {
            return null;
        }

        $displayName = is_string($entry['displayName'] ?? null) ? trim($entry['displayName']) : '';

        return [
            'id' => (string) $id,
            'name' => $name,
            'display_name' => $displayName !== '' ? $displayName : null,
            'rarity' => is_int($entry['rarity'] ?? null) ? $entry['rarity'] : null,
            'category' => $category,
            // Game version that released the item ("3.4"): tells a rerun banner from a new one
            'release' => is_array($entry['release'] ?? null) && is_string($entry['release']['version'] ?? null) && trim($entry['release']['version']) !== '' ? trim($entry['release']['version']) : null,
        ];
    }
}
