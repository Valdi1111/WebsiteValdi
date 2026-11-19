<?php

namespace App\HoyoverseBundle\Service\PullIcon;

use App\CoreBundle\Model\Game\PullItemIconQuery;
use App\CoreBundle\Model\Game\PullItemIconResolverInterface;
use App\HoyoverseBundle\Service\GenshinImpactService;
use App\HoyoverseBundle\Service\HonkaiStarRailService;
use App\HoyoverseBundle\Service\ZenlessZoneZeroService;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class NanokaIconResolver implements PullItemIconResolverInterface
{
    private const string CACHE_KEY_MANIFEST = 'nanoka_manifest_v1';
    private const string CACHE_KEY_ZZZ = 'nanoka_zzz_catalog_v2';
    private const string CACHE_KEY_GI = 'nanoka_gi_catalog_v1';

    /** @var array<string, mixed>|null */
    private ?array $zzzCatalog = null;

    /** @var array<string, mixed>|null */
    private ?array $giCatalog = null;

    public function __construct(
        #[Target('hoyoverse.nanoka.client')]
        private readonly HttpClientInterface $httpClient,
        #[Target('hoyoverse.item_icons.cache')]
        private readonly CacheInterface $cache,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public static function getSupportedGameServices(): array
    {
        return [
            ZenlessZoneZeroService::class => 100,
            HonkaiStarRailService::class => 100,
            GenshinImpactService::class => 100,
        ];
    }

    public function resolveUrl(string|object $gameService, PullItemIconQuery $query): ?string
    {
        $serviceClass = is_object($gameService) ? $gameService::class : $gameService;

        return match (true) {
            is_a($serviceClass, ZenlessZoneZeroService::class, true) => $this->resolveZzz($query),
            is_a($serviceClass, HonkaiStarRailService::class, true) => $this->resolveHsr($query),
            is_a($serviceClass, GenshinImpactService::class, true) => $this->resolveGenshin($query),
            default => null,
        };
    }

    private function resolveZzz(PullItemIconQuery $query): ?string
    {
        $catalog = $this->getZzzCatalog();
        if ($catalog === []) {
            return null;
        }

        $icon = null;
        $type = $this->normalizeType($query->itemType);

        if ($query->itemId !== null && $query->itemId !== '') {
            $id = trim($query->itemId);
            if ($type === 'character') {
                $icon = $catalog['id_char'][$id] ?? null;
            } elseif ($type === 'weapon') {
                $icon = $catalog['id_weapon'][$id] ?? null;
            } elseif ($type === 'bangboo') {
                $icon = $catalog['id_bangboo'][$id] ?? null;
            } else {
                $icon = $catalog['id_char'][$id]
                    ?? $catalog['id_weapon'][$id]
                    ?? $catalog['id_bangboo'][$id]
                    ?? null;
            }
        }

        if ($icon === null && $query->name !== null && trim($query->name) !== '') {
            $exact = $this->normalizeExact($query->name);
            $loose = $this->normalizeLoose($query->name);

            if ($type === 'character') {
                $icon = $catalog['char_exact'][$exact] ?? $catalog['char_loose'][$loose] ?? null;
            } elseif ($type === 'weapon') {
                $icon = $catalog['weapon_exact'][$exact] ?? $catalog['weapon_loose'][$loose] ?? null;
            } elseif ($type === 'bangboo') {
                $icon = $catalog['bangboo_exact'][$exact] ?? $catalog['bangboo_loose'][$loose] ?? null;
            } else {
                $icon = $catalog['char_exact'][$exact]
                    ?? $catalog['weapon_exact'][$exact]
                    ?? $catalog['bangboo_exact'][$exact]
                    ?? $catalog['char_loose'][$loose]
                    ?? $catalog['weapon_loose'][$loose]
                    ?? $catalog['bangboo_loose'][$loose]
                    ?? null;
            }
        }

        return $icon !== null ? sprintf('https://static.nanoka.cc/assets/zzz/%s.webp', $icon) : null;
    }

    private function resolveHsr(PullItemIconQuery $query): ?string
    {
        $itemId = $query->itemId !== null ? trim($query->itemId) : '';
        if ($itemId === '' || !ctype_digit($itemId)) {
            return null;
        }

        $type = $this->normalizeType($query->itemType);
        $isWeapon = $type === 'weapon' || ($type === null && strlen($itemId) >= 5 && str_starts_with($itemId, '2'));
        $isChar = $type === 'character' || ($type === null && strlen($itemId) === 4);

        if ($isChar) {
            return sprintf('https://static.nanoka.cc/assets/hsr/avatarroundicon/%s.webp', $itemId);
        }
        if ($isWeapon) {
            return sprintf('https://static.nanoka.cc/assets/hsr/lightconemediumicon/%s.webp', $itemId);
        }

        return null;
    }

    private function resolveGenshin(PullItemIconQuery $query): ?string
    {
        $catalog = $this->getGiCatalog();
        if ($catalog === []) {
            return null;
        }

        $icon = null;
        $type = $this->normalizeType($query->itemType);

        if ($query->itemId !== null && $query->itemId !== '') {
            $id = trim($query->itemId);
            if ($type === 'character') {
                $icon = $catalog['id_char'][$id] ?? null;
            } elseif ($type === 'weapon') {
                $icon = $catalog['id_weapon'][$id] ?? null;
            } else {
                $icon = $catalog['id_char'][$id] ?? $catalog['id_weapon'][$id] ?? null;
            }
        }

        if ($icon === null && $query->name !== null && trim($query->name) !== '') {
            $exact = $this->normalizeExact($query->name);
            $loose = $this->normalizeLoose($query->name);

            if ($type === 'character') {
                $icon = $catalog['char_exact'][$exact] ?? $catalog['char_loose'][$loose] ?? null;
            } elseif ($type === 'weapon') {
                $icon = $catalog['weapon_exact'][$exact] ?? $catalog['weapon_loose'][$loose] ?? null;
            } else {
                $icon = $catalog['char_exact'][$exact]
                    ?? $catalog['weapon_exact'][$exact]
                    ?? $catalog['char_loose'][$loose]
                    ?? $catalog['weapon_loose'][$loose]
                    ?? null;
            }
        }

        return $icon !== null ? sprintf('https://static.nanoka.cc/assets/gi/%s.webp', $icon) : null;
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
                $this->logger?->warning('NanokaIconResolver failed fetching manifest.json', ['error' => $e->getMessage()]);
                $item->expiresAfter(300);
                return [];
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function getZzzCatalog(): array
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
                    $icon = $entry['icon'] ?? null;
                    $name = $entry['en'] ?? $entry['code'] ?? null;
                    if (!is_string($icon) || $icon === '') {
                        continue;
                    }
                    if (str_starts_with($icon, 'IconRole') && !str_starts_with($icon, 'IconRoleCircle')) {
                        $icon = 'IconRoleCircle' . substr($icon, 8);
                    }
                    $catalog['id_char'][(string) $id] = $icon;
                    if (is_string($name) && $name !== '') {
                        $catalog['char_exact'][$this->normalizeExact($name)] = $icon;
                        $catalog['char_loose'][$this->normalizeLoose($name)] = $icon;
                    }
                }

                $weapons = $this->httpClient->request('GET', sprintf('/zzz/%s/weapon.json', $version))->toArray();
                foreach ($weapons as $id => $entry) {
                    $icon = $entry['icon'] ?? null;
                    $name = $entry['en'] ?? null;
                    if (!is_string($icon) || $icon === '') {
                        continue;
                    }
                    $catalog['id_weapon'][(string) $id] = $icon;
                    if (is_string($name) && $name !== '') {
                        $catalog['weapon_exact'][$this->normalizeExact($name)] = $icon;
                        $catalog['weapon_loose'][$this->normalizeLoose($name)] = $icon;
                    }
                }

                $bangboos = $this->httpClient->request('GET', sprintf('/zzz/%s/bangboo.json', $version))->toArray();
                foreach ($bangboos as $id => $entry) {
                    $rawIcon = $entry['icon'] ?? null;
                    $name = $entry['en'] ?? $entry['codename'] ?? null;
                    if (!is_string($rawIcon) || $rawIcon === '') {
                        continue;
                    }
                    $iconName = pathinfo($rawIcon, PATHINFO_FILENAME);
                    if ($iconName === '') {
                        continue;
                    }
                    $catalog['id_bangboo'][(string) $id] = $iconName;
                    if (is_string($name) && $name !== '') {
                        $catalog['bangboo_exact'][$this->normalizeExact($name)] = $iconName;
                        $catalog['bangboo_loose'][$this->normalizeLoose($name)] = $iconName;
                    }
                }
            } catch (\Throwable $e) {
                $this->logger?->warning('NanokaIconResolver failed fetching ZZZ catalog', ['error' => $e->getMessage()]);
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
    private function getGiCatalog(): array
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
                    $icon = $entry['icon'] ?? null;
                    $name = $entry['en'] ?? null;
                    if (!is_string($icon) || $icon === '') {
                        continue;
                    }
                    $catalog['id_char'][(string) $id] = $icon;
                    if (is_string($name) && $name !== '') {
                        $catalog['char_exact'][$this->normalizeExact($name)] = $icon;
                        $catalog['char_loose'][$this->normalizeLoose($name)] = $icon;
                    }
                }

                $weapons = $this->httpClient->request('GET', sprintf('/gi/%s/weapon.json', $version))->toArray();
                foreach ($weapons as $id => $entry) {
                    $icon = $entry['icon'] ?? null;
                    $name = $entry['en'] ?? null;
                    if (!is_string($icon) || $icon === '') {
                        continue;
                    }
                    $catalog['id_weapon'][(string) $id] = $icon;
                    if (is_string($name) && $name !== '') {
                        $catalog['weapon_exact'][$this->normalizeExact($name)] = $icon;
                        $catalog['weapon_loose'][$this->normalizeLoose($name)] = $icon;
                    }
                }
            } catch (\Throwable $e) {
                $this->logger?->warning('NanokaIconResolver failed fetching GI catalog', ['error' => $e->getMessage()]);
                $item->expiresAfter(300);
                return [];
            }

            $item->expiresAfter(86400);
            return $catalog;
        });
    }

    private function normalizeType(?string $itemType): ?string
    {
        if ($itemType === null || trim($itemType) === '') {
            return null;
        }

        $t = strtolower(trim($itemType));
        if (in_array($t, ['character', 'char', 'agent', 'agents'], true)) {
            return 'character';
        }
        if (in_array($t, ['weapon', 'w-engine', 'wengine', 'light-cone', 'light cone'], true)) {
            return 'weapon';
        }
        if (in_array($t, ['bangboo', 'bangboos'], true)) {
            return 'bangboo';
        }

        return null;
    }

    private function normalizeExact(string $value): string
    {
        return mb_strtolower(trim($value));
    }

    private function normalizeLoose(string $value): string
    {
        return (string) preg_replace('/[^a-z0-9]+/', '', mb_strtolower(trim($value)));
    }
}
