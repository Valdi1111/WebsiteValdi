<?php

namespace App\GryphlineBundle\Service\PullIcon;

use App\CoreBundle\Model\Game\PullItemIconQuery;
use App\CoreBundle\Model\Game\PullItemIconResolverInterface;
use App\GryphlineBundle\Service\EndfieldService;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class AkeDataIconResolver implements PullItemIconResolverInterface
{
    private const string CACHE_KEY_ENDFIELD = 'akedata_endfield_catalog_v1';

    /** @var array<string, mixed>|null */
    private ?array $endfieldCatalog = null;

    public function __construct(
        #[Target('gryphline.akedata.client')]
        private readonly HttpClientInterface $httpClient,
        #[Target('gryphline.item_icons.cache')]
        private readonly CacheInterface $cache,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public static function getSupportedGameServices(): array
    {
        return [
            EndfieldService::class => 100,
        ];
    }

    public function resolveUrl(string|object $gameService, PullItemIconQuery $query): ?string
    {
        $serviceClass = is_object($gameService) ? $gameService::class : $gameService;
        if (!is_a($serviceClass, EndfieldService::class, true)) {
            return null;
        }

        $itemId = $query->itemId !== null ? trim($query->itemId) : '';
        if ($itemId === '') {
            return null;
        }

        $catalog = $this->getEndfieldCatalog();
        if ($catalog === []) {
            return null;
        }

        $root = rtrim((string) ($catalog['root'] ?? 'public/images'), '/');
        $type = $this->normalizeType($query->itemType);

        $path = null;
        if ($type === 'char' || str_starts_with($itemId, 'chr_')) {
            $path = $catalog['chars'][$itemId] ?? null;
        }
        if ($path === null) {
            $path = $catalog['items'][$itemId] ?? $catalog['chars'][$itemId] ?? null;
        }

        if ($path === null) {
            return null;
        }

        return sprintf('https://data.akedata.wiki/%s/%s', $root, ltrim($path, '/'));
    }

    /**
     * @return array<string, mixed>
     */
    private function getEndfieldCatalog(): array
    {
        if ($this->endfieldCatalog !== null) {
            return $this->endfieldCatalog;
        }

        return $this->endfieldCatalog = $this->cache->get(self::CACHE_KEY_ENDFIELD, function (ItemInterface $item): array {
            try {
                $manifest = $this->httpClient->request('GET', '/manifest.json')->toArray();
                $latestId = $manifest['latest'] ?? null;
                $tableCfgPath = null;
                foreach ($manifest['versions'] ?? [] as $ver) {
                    if (($ver['id'] ?? null) === $latestId) {
                        $tableCfgPath = $ver['tableCfgPath'] ?? null;
                        break;
                    }
                }
                if (!is_string($tableCfgPath) || $tableCfgPath === '') {
                    $tableCfgPath = $manifest['versions'][0]['tableCfgPath'] ?? null;
                }
                if (!is_string($tableCfgPath) || $tableCfgPath === '') {
                    $item->expiresAfter(300);
                    return [];
                }

                $assetIndex = $this->httpClient->request('GET', '/asset-sync-index.json')->toArray();
                $imagesDataset = $assetIndex['datasets']['images'] ?? [];
                $root = (string) ($imagesDataset['root'] ?? 'public/images');
                $files = $imagesDataset['files'] ?? [];

                $chars = [];
                $byItemIcon = [];
                $byGachaWeapon = [];

                foreach (array_keys($files) as $relPath) {
                    $lower = strtolower($relPath);
                    if (str_contains($lower, '/charremoteicon/icon_') && str_ends_with($lower, '.png')) {
                        $filename = pathinfo($relPath, PATHINFO_FILENAME);
                        $charId = substr($filename, 5); // strip "icon_"
                        if ($charId !== '') {
                            $chars[$charId] = $relPath;
                        }
                    } elseif (str_contains($lower, '/itemicon/') && str_ends_with($lower, '.png')) {
                        $iconId = pathinfo($relPath, PATHINFO_FILENAME);
                        $byItemIcon[$iconId] = $relPath;
                    } elseif (str_contains($lower, '/gachaweapon/') && str_ends_with($lower, '.png')) {
                        $iconId = pathinfo($relPath, PATHINFO_FILENAME);
                        $byGachaWeapon[$iconId] = $relPath;
                    }
                }

                $itemTable = $this->httpClient->request('GET', '/' . ltrim($tableCfgPath, '/') . '/ItemTable.json')->toArray();
                $items = [];
                foreach ($itemTable as $id => $entry) {
                    $idStr = (string) $id;
                    $iconId = $entry['iconId'] ?? null;
                    if (!is_string($iconId) || $iconId === '') {
                        continue;
                    }

                    // Prefer compact square itemicon; fallback to gachaweapon if missing
                    $matched = $byItemIcon[$iconId] ?? $byGachaWeapon[$iconId] ?? null;
                    if ($matched !== null) {
                        $items[$idStr] = $matched;
                    }
                }

                // Direct fallback for weapons whose path matches ID even if missing from ItemTable
                foreach ($byItemIcon as $iconId => $relPath) {
                    if (str_starts_with($iconId, 'wpn_') && !isset($items[$iconId])) {
                        $items[$iconId] = $relPath;
                    }
                }

                $catalog = [
                    'root' => $root,
                    'chars' => $chars,
                    'items' => $items,
                ];

                $item->expiresAfter(86400);
                return $catalog;
            } catch (\Throwable $e) {
                $this->logger?->warning('AkeDataIconResolver failed fetching Endfield catalog', ['error' => $e->getMessage()]);
                $item->expiresAfter(300);
                return [];
            }
        });
    }

    private function normalizeType(?string $itemType): ?string
    {
        if ($itemType === null || trim($itemType) === '') {
            return null;
        }

        $t = strtolower(trim($itemType));
        if (in_array($t, ['char', 'character', 'operator'], true)) {
            return 'char';
        }
        if ($t === 'weapon') {
            return 'weapon';
        }

        return null;
    }
}
