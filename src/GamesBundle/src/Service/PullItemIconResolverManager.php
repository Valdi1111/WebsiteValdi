<?php

namespace App\GamesBundle\Service;

use App\GamesBundle\Model\Game\PullItemIconQuery;
use App\GamesBundle\Model\Game\PullItemIconResolverInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

class PullItemIconResolverManager
{
    /** @var array<class-string, list<array{priority: int, resolver: PullItemIconResolverInterface}>> */
    private array $resolversByService = [];

    /** @var array<string, list<array{priority: int, serviceClass: class-string, resolver: PullItemIconResolverInterface}>> */
    private array $resolversByGameBiz = [];

    /**
     * @param iterable<PullItemIconResolverInterface> $resolvers
     */
    public function __construct(
        #[AutowireIterator('games.pull_item_icon_resolver')]
        iterable $resolvers,
        private readonly ?LoggerInterface $logger = null,
    ) {
        foreach ($resolvers as $resolver) {
            foreach ($resolver::getSupportedGameServices() as $serviceClass => $priority) {
                $this->resolversByService[$serviceClass][] = [
                    'priority' => (int) $priority,
                    'resolver' => $resolver,
                ];

                if (method_exists($serviceClass, 'getGameBiz')) {
                    $gameBiz = (string) $serviceClass::getGameBiz();
                    if ($gameBiz !== '') {
                        $this->resolversByGameBiz[$gameBiz][] = [
                            'priority' => (int) $priority,
                            'serviceClass' => $serviceClass,
                            'resolver' => $resolver,
                        ];
                    }
                }
            }
        }

        foreach ($this->resolversByService as &$entries) {
            usort($entries, static fn (array $a, array $b): int => $b['priority'] <=> $a['priority']);
        }
        unset($entries);

        foreach ($this->resolversByGameBiz as &$entries) {
            usort($entries, static fn (array $a, array $b): int => $b['priority'] <=> $a['priority']);
        }
        unset($entries);
    }

    public function supportsGameService(string|object $gameService): bool
    {
        return $this->resolveServiceClass($gameService) !== null;
    }

    public function supportsGameBiz(string $gameBiz): bool
    {
        return !empty($this->resolversByGameBiz[$gameBiz]);
    }

    public function getGameBizForService(string|object $gameService): ?string
    {
        $serviceClass = is_object($gameService) ? $gameService::class : $gameService;
        if (method_exists($serviceClass, 'getGameBiz')) {
            $biz = (string) $serviceClass::getGameBiz();
            return $biz !== '' ? $biz : null;
        }

        return null;
    }

    /**
     * @param class-string|object $gameService
     * @return list<string>
     */
    public function resolveCandidateUrls(string|object $gameService, PullItemIconQuery $query): array
    {
        $matchedClass = $this->resolveServiceClass($gameService);
        if ($matchedClass === null) {
            return [];
        }

        $urls = [];
        foreach ($this->resolversByService[$matchedClass] as $entry) {
            $resolver = $entry['resolver'];
            try {
                $url = $resolver->resolveUrl($matchedClass, $query);
                if ($url !== null && $url !== '' && !in_array($url, $urls, true)) {
                    $urls[] = $url;
                }
            } catch (\Throwable $e) {
                $this->logger?->warning('Pull item icon resolver failed', [
                    'resolver' => $resolver::class,
                    'game_service' => $matchedClass,
                    'item_id' => $query->itemId,
                    'name' => $query->name,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $urls;
    }

    /**
     * @return list<string>
     */
    public function resolveCandidateUrlsByGameBiz(string $gameBiz, PullItemIconQuery $query): array
    {
        $entries = $this->resolversByGameBiz[$gameBiz] ?? [];
        if ($entries === []) {
            return [];
        }

        $urls = [];
        foreach ($entries as $entry) {
            $resolver = $entry['resolver'];
            $serviceClass = $entry['serviceClass'];
            try {
                $url = $resolver->resolveUrl($serviceClass, $query);
                if ($url !== null && $url !== '' && !in_array($url, $urls, true)) {
                    $urls[] = $url;
                }
            } catch (\Throwable $e) {
                $this->logger?->warning('Pull item icon resolver failed', [
                    'resolver' => $resolver::class,
                    'game_biz' => $gameBiz,
                    'game_service' => $serviceClass,
                    'item_id' => $query->itemId,
                    'name' => $query->name,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $urls;
    }

    /**
     * @return list<array{resolver: class-string, priority: int, url: ?string, error: ?string}>
     */
    public function resolveCandidateUrlsDetailedByGameBiz(string $gameBiz, PullItemIconQuery $query): array
    {
        $entries = $this->resolversByGameBiz[$gameBiz] ?? [];
        $results = [];

        foreach ($entries as $entry) {
            $resolver = $entry['resolver'];
            $serviceClass = $entry['serviceClass'];
            $url = null;
            $error = null;

            try {
                $url = $resolver->resolveUrl($serviceClass, $query);
                if ($url === '') {
                    $url = null;
                }
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }

            $results[] = [
                'resolver' => $resolver::class,
                'priority' => $entry['priority'],
                'url' => $url,
                'error' => $error,
            ];
        }

        return $results;
    }

    /**
     * @param class-string|object $gameService
     */
    public function resolveUrl(string|object $gameService, PullItemIconQuery $query): ?string
    {
        return $this->resolveCandidateUrls($gameService, $query)[0] ?? null;
    }

    /**
     * @param class-string|object $gameService
     * @return class-string|null
     */
    private function resolveServiceClass(string|object $gameService): ?string
    {
        $serviceClass = is_object($gameService) ? $gameService::class : $gameService;
        if (isset($this->resolversByService[$serviceClass])) {
            return $serviceClass;
        }

        foreach (array_keys($this->resolversByService) as $registeredClass) {
            if (is_a($serviceClass, $registeredClass, true)) {
                return $registeredClass;
            }
        }

        return null;
    }
}
