<?php

namespace App\HoyoverseBundle\Service\Pull;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Shared cached access to static.nanoka.cc item catalogs for Genshin Impact,
 * Honkai: Star Rail, and Zenless Zone Zero.
 *
 * Used by both icon resolution and backup pull importers to enrich item names,
 * item types, rarities, and icon keys without duplicating HTTP requests or caches.
 */
class NanokaCatalogService
{
    private const string CACHE_KEY_MANIFEST = 'nanoka_manifest_v1';
    private const string CACHE_KEY_GI = 'nanoka_gi_catalog_v2';
    private const string CACHE_KEY_HSR = 'nanoka_hsr_catalog_v1';
    private const string CACHE_KEY_ZZZ = 'nanoka_zzz_catalog_v3';

    /** @var array<string, mixed>|null */
    private ?array $giCatalog = null;

    /** @var array<string, mixed>|null */
    private ?array $hsrCatalog = null;

    /** @var array<string, mixed>|null */
    private ?array $zzzCatalog = null;

    public function __construct(
        #[Target('hoyoverse.nanoka.client')]
        private readonly HttpClientInterface $httpClient,
        #[Target('hoyoverse.item_icons.cache')]
        private readonly CacheInterface $cache,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @return array{id: string, name: string, rarity: int, icon: ?string, item_type: string}|null
     */
    public function findGenshinItem(?string $itemId, ?string $nameOrSlug, ?string $normalizedType = null): ?array
    {
        $catalog = $this->getGiCatalog();
        if ($catalog === []) {
            return null;
        }

        if ($itemId !== null && trim($itemId) !== '') {
            $id = trim($itemId);
            if ($normalizedType === 'character') {
                return $catalog['id_char'][$id] ?? null;
            }
            if ($normalizedType === 'weapon') {
                return $catalog['id_weapon'][$id] ?? null;
            }

            $found = $catalog['id_char'][$id] ?? $catalog['id_weapon'][$id] ?? null;
            if ($found !== null) {
                return $found;
            }
        }

        if ($nameOrSlug !== null && trim($nameOrSlug) !== '') {
            $exact = self::normalizeExact($nameOrSlug);
            $loose = self::normalizeLoose($nameOrSlug);

            if ($normalizedType === 'character') {
                return $catalog['char_exact'][$exact] ?? $catalog['char_loose'][$loose] ?? null;
            }
            if ($normalizedType === 'weapon') {
                return $catalog['weapon_exact'][$exact] ?? $catalog['weapon_loose'][$loose] ?? null;
            }

            return $catalog['char_exact'][$exact]
                ?? $catalog['weapon_exact'][$exact]
                ?? $catalog['char_loose'][$loose]
                ?? $catalog['weapon_loose'][$loose]
                ?? null;
        }

        return null;
    }

    /**
     * @return array{id: string, name: string, rarity: int, item_type: string}|null
     */
    public function findHsrItemById(string $itemId): ?array
    {
        $id = trim($itemId);
        if ($id === '') {
            return null;
        }

        $catalog = $this->getHsrCatalog();

        return $catalog['items_by_id'][$id] ?? null;
    }

    /**
     * @return array{id: string, name: string, rarity: int, icon: ?string, item_type: string}|null
     */
    public function findZzzItem(?string $itemId, ?string $name, ?string $normalizedType = null): ?array
    {
        $catalog = $this->getZzzCatalog();
        if ($catalog === []) {
            return null;
        }

        if ($itemId !== null && trim($itemId) !== '') {
            $id = trim($itemId);
            if ($normalizedType === 'character') {
                return $catalog['id_char'][$id] ?? null;
            }
            if ($normalizedType === 'weapon') {
                return $catalog['id_weapon'][$id] ?? null;
            }
            if ($normalizedType === 'bangboo') {
                return $catalog['id_bangboo'][$id] ?? null;
            }

            $found = $catalog['id_char'][$id]
                ?? $catalog['id_weapon'][$id]
                ?? $catalog['id_bangboo'][$id]
                ?? null;
            if ($found !== null) {
                return $found;
            }
        }

        if ($name !== null && trim($name) !== '') {
            $exact = self::normalizeExact($name);
            $loose = self::normalizeLoose($name);

            if ($normalizedType === 'character') {
                return $catalog['char_exact'][$exact] ?? $catalog['char_loose'][$loose] ?? null;
            }
            if ($normalizedType === 'weapon') {
                return $catalog['weapon_exact'][$exact] ?? $catalog['weapon_loose'][$loose] ?? null;
            }
            if ($normalizedType === 'bangboo') {
                return $catalog['bangboo_exact'][$exact] ?? $catalog['bangboo_loose'][$loose] ?? null;
            }

            return $catalog['char_exact'][$exact]
                ?? $catalog['weapon_exact'][$exact]
                ?? $catalog['bangboo_exact'][$exact]
                ?? $catalog['char_loose'][$loose]
                ?? $catalog['weapon_loose'][$loose]
                ?? $catalog['bangboo_loose'][$loose]
                ?? null;
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function getGiCatalog(): array
    {
        if ($this->giCatalog !== null) {
            return $this->giCatalog;
        }

        return $this->giCatalog = $this->cache->get(self::CACHE_KEY_GI, function (ItemInterface $item): array {
            $manifest = $this->getManifest();
            $version = $manifest['gi']['live'] ?? $manifest['gi']['latest'] ?? null;
            if (!is_string($version) || $version === '') {
                $item->expiresAfter(300);

                return [];
            }

            $catalog = [
                'id_char' => [],
                'id_weapon' => [],
                'char_exact' => [],
                'char_loose' => [],
                'weapon_exact' => [],
                'weapon_loose' => [],
            ];

            try {
                $chars = $this->httpClient->request('GET', sprintf('/gi/%s/character.json', $version))->toArray();
                foreach ($chars as $id => $entry) {
                    if (!is_array($entry)) {
                        continue;
                    }
                    $name = isset($entry['en']) && is_string($entry['en']) ? trim($entry['en']) : '';
                    $icon = isset($entry['icon']) && is_string($entry['icon']) && $entry['icon'] !== '' ? $entry['icon'] : null;
                    if ($name === '' && $icon === null) {
                        continue;
                    }
                    $rarity = $this->parseGiCharacterRarity($entry['rank'] ?? null);
                    $record = [
                        'id' => (string) $id,
                        'name' => $name,
                        'rarity' => $rarity,
                        'icon' => $icon,
                        'item_type' => 'Character',
                    ];
                    $catalog['id_char'][(string) $id] = $record;
                    if ($name !== '') {
                        $catalog['char_exact'][self::normalizeExact($name)] = $record;
                        $catalog['char_loose'][self::normalizeLoose($name)] = $record;
                    }
                }

                $weapons = $this->httpClient->request('GET', sprintf('/gi/%s/weapon.json', $version))->toArray();
                foreach ($weapons as $id => $entry) {
                    if (!is_array($entry)) {
                        continue;
                    }
                    $name = isset($entry['en']) && is_string($entry['en']) ? trim($entry['en']) : '';
                    $icon = isset($entry['icon']) && is_string($entry['icon']) && $entry['icon'] !== '' ? $entry['icon'] : null;
                    if ($name === '' && $icon === null) {
                        continue;
                    }
                    $rarity = isset($entry['rank']) && is_numeric($entry['rank']) ? (int) $entry['rank'] : 3;
                    $record = [
                        'id' => (string) $id,
                        'name' => $name,
                        'rarity' => $rarity,
                        'icon' => $icon,
                        'item_type' => 'Weapon',
                    ];
                    $catalog['id_weapon'][(string) $id] = $record;
                    if ($name !== '') {
                        $catalog['weapon_exact'][self::normalizeExact($name)] = $record;
                        $catalog['weapon_loose'][self::normalizeLoose($name)] = $record;
                    }
                }
            } catch (\Throwable $e) {
                $this->logger?->warning('NanokaCatalogService failed fetching GI catalog', ['error' => $e->getMessage()]);
                $item->expiresAfter(300);

                return [];
            }

            $item->expiresAfter(86400);

            return $catalog;
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function getHsrCatalog(): array
    {
        if ($this->hsrCatalog !== null) {
            return $this->hsrCatalog;
        }

        return $this->hsrCatalog = $this->cache->get(self::CACHE_KEY_HSR, function (ItemInterface $item): array {
            $manifest = $this->getManifest();
            $version = $manifest['hsr']['live'] ?? $manifest['hsr']['latest'] ?? null;
            if (!is_string($version) || $version === '') {
                $item->expiresAfter(300);

                return [];
            }

            $catalog = ['items_by_id' => []];

            try {
                $chars = $this->httpClient->request('GET', sprintf('/hsr/%s/character.json', $version))->toArray();
                foreach ($chars as $id => $entry) {
                    if (!is_array($entry)) {
                        continue;
                    }
                    $name = isset($entry['en']) && is_string($entry['en']) ? self::stripRichTextTags($entry['en']) : '';
                    if ($name === '') {
                        continue;
                    }
                    $catalog['items_by_id'][(string) $id] = [
                        'id' => (string) $id,
                        'name' => $name,
                        'rarity' => $this->parseTrailingDigitsRarity($entry['rank'] ?? null, 4),
                        'item_type' => 'Character',
                    ];
                }

                $lightCones = $this->httpClient->request('GET', sprintf('/hsr/%s/lightcone.json', $version))->toArray();
                foreach ($lightCones as $id => $entry) {
                    if (!is_array($entry)) {
                        continue;
                    }
                    $name = isset($entry['en']) && is_string($entry['en']) ? self::stripRichTextTags($entry['en']) : '';
                    if ($name === '') {
                        continue;
                    }
                    $catalog['items_by_id'][(string) $id] = [
                        'id' => (string) $id,
                        'name' => $name,
                        'rarity' => $this->parseTrailingDigitsRarity($entry['rank'] ?? null, 3),
                        'item_type' => 'Light Cone',
                    ];
                }
            } catch (\Throwable $e) {
                $this->logger?->warning('NanokaCatalogService failed fetching HSR catalog', ['error' => $e->getMessage()]);
                $item->expiresAfter(300);

                return [];
            }

            $item->expiresAfter(86400);

            return $catalog;
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function getZzzCatalog(): array
    {
        if ($this->zzzCatalog !== null) {
            return $this->zzzCatalog;
        }

        return $this->zzzCatalog = $this->cache->get(self::CACHE_KEY_ZZZ, function (ItemInterface $item): array {
            $manifest = $this->getManifest();
            $version = $manifest['zzz']['live'] ?? $manifest['zzz']['latest'] ?? null;
            if (!is_string($version) || $version === '') {
                $item->expiresAfter(300);

                return [];
            }

            $catalog = [
                'id_char' => [],
                'id_weapon' => [],
                'id_bangboo' => [],
                'char_exact' => [],
                'char_loose' => [],
                'weapon_exact' => [],
                'weapon_loose' => [],
                'bangboo_exact' => [],
                'bangboo_loose' => [],
            ];

            try {
                $chars = $this->httpClient->request('GET', sprintf('/zzz/%s/character.json', $version))->toArray();
                foreach ($chars as $id => $entry) {
                    if (!is_array($entry)) {
                        continue;
                    }
                    $rawIcon = isset($entry['icon']) && is_string($entry['icon']) && $entry['icon'] !== '' ? $entry['icon'] : null;
                    if ($rawIcon !== null && str_starts_with($rawIcon, 'IconRole') && !str_starts_with($rawIcon, 'IconRoleCircle')) {
                        $rawIcon = 'IconRoleCircle' . substr($rawIcon, 8);
                    }
                    $name = isset($entry['en']) && is_string($entry['en']) && trim($entry['en']) !== ''
                        ? trim($entry['en'])
                        : (isset($entry['code']) && is_string($entry['code']) ? trim($entry['code']) : '');
                    if ($name === '' && $rawIcon === null) {
                        continue;
                    }
                    $record = [
                        'id' => (string) $id,
                        'name' => $name,
                        'rarity' => isset($entry['rank']) && is_numeric($entry['rank']) ? (int) $entry['rank'] : 3,
                        'icon' => $rawIcon,
                        'item_type' => 'Agents',
                    ];
                    $catalog['id_char'][(string) $id] = $record;
                    if ($name !== '') {
                        $catalog['char_exact'][self::normalizeExact($name)] = $record;
                        $catalog['char_loose'][self::normalizeLoose($name)] = $record;
                    }
                }

                $weapons = $this->httpClient->request('GET', sprintf('/zzz/%s/weapon.json', $version))->toArray();
                foreach ($weapons as $id => $entry) {
                    if (!is_array($entry)) {
                        continue;
                    }
                    $icon = isset($entry['icon']) && is_string($entry['icon']) && $entry['icon'] !== '' ? $entry['icon'] : null;
                    $name = isset($entry['en']) && is_string($entry['en']) ? trim($entry['en']) : '';
                    if ($name === '' && $icon === null) {
                        continue;
                    }
                    $record = [
                        'id' => (string) $id,
                        'name' => $name,
                        'rarity' => isset($entry['rank']) && is_numeric($entry['rank']) ? (int) $entry['rank'] : 2,
                        'icon' => $icon,
                        'item_type' => 'W-Engines',
                    ];
                    $catalog['id_weapon'][(string) $id] = $record;
                    if ($name !== '') {
                        $catalog['weapon_exact'][self::normalizeExact($name)] = $record;
                        $catalog['weapon_loose'][self::normalizeLoose($name)] = $record;
                    }
                }

                $bangboos = $this->httpClient->request('GET', sprintf('/zzz/%s/bangboo.json', $version))->toArray();
                foreach ($bangboos as $id => $entry) {
                    if (!is_array($entry)) {
                        continue;
                    }
                    $rawIcon = isset($entry['icon']) && is_string($entry['icon']) && $entry['icon'] !== '' ? $entry['icon'] : null;
                    $iconName = $rawIcon !== null ? pathinfo($rawIcon, PATHINFO_FILENAME) : null;
                    if ($iconName === '') {
                        $iconName = null;
                    }
                    $name = isset($entry['en']) && is_string($entry['en']) && trim($entry['en']) !== ''
                        ? trim($entry['en'])
                        : (isset($entry['codename']) && is_string($entry['codename']) ? trim($entry['codename']) : '');
                    if ($name === '' && $iconName === null) {
                        continue;
                    }
                    $record = [
                        'id' => (string) $id,
                        'name' => $name,
                        'rarity' => isset($entry['rank']) && is_numeric($entry['rank']) ? (int) $entry['rank'] : 3,
                        'icon' => $iconName,
                        'item_type' => 'Bangboo',
                    ];
                    $catalog['id_bangboo'][(string) $id] = $record;
                    if ($name !== '') {
                        $catalog['bangboo_exact'][self::normalizeExact($name)] = $record;
                        $catalog['bangboo_loose'][self::normalizeLoose($name)] = $record;
                    }
                }
            } catch (\Throwable $e) {
                $this->logger?->warning('NanokaCatalogService failed fetching ZZZ catalog', ['error' => $e->getMessage()]);
                $item->expiresAfter(300);

                return [];
            }

            $item->expiresAfter(86400);

            return $catalog;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function getManifest(): array
    {
        return $this->cache->get(self::CACHE_KEY_MANIFEST, function (ItemInterface $item): array {
            try {
                $data = $this->httpClient->request('GET', '/manifest.json')->toArray();
                $item->expiresAfter(21600);

                return $data;
            } catch (\Throwable $e) {
                $this->logger?->warning('NanokaCatalogService failed fetching manifest.json', ['error' => $e->getMessage()]);
                $item->expiresAfter(300);

                return [];
            }
        });
    }

    private function parseGiCharacterRarity(mixed $rank): int
    {
        if (is_numeric($rank)) {
            return (int) $rank;
        }
        if (is_string($rank)) {
            if (str_contains($rank, 'ORANGE')) {
                return 5;
            }
            if (str_contains($rank, 'PURPLE')) {
                return 4;
            }
        }

        return 4;
    }

    private function parseTrailingDigitsRarity(mixed $rank, int $default): int
    {
        if (is_numeric($rank)) {
            return (int) $rank;
        }
        if (is_string($rank) && preg_match('/(\d+)$/', $rank, $m)) {
            return (int) $m[1];
        }

        return $default;
    }

    public static function stripRichTextTags(string $value): string
    {
        return trim((string) preg_replace('/<\/?[a-zA-Z0-9_=#"\'\s-]+>/', '', $value));
    }

    public static function normalizeExact(string $value): string
    {
        return mb_strtolower(trim($value));
    }

    public static function normalizeLoose(string $value): string
    {
        return (string) preg_replace('/[^a-z0-9]+/', '', mb_strtolower(trim($value)));
    }
}
