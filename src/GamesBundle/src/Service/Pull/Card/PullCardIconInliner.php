<?php

namespace App\GamesBundle\Service\Pull\Card;

use App\GamesBundle\Service\PullItemIconUrlBuilder;
use Liip\ImagineBundle\Service\FilterService;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Pull item icons as data URIs, so that the card renders without any network access: the resized icon is taken
 * from the LiipImagine cache, which is filled first when the icon was never shown.
 */
class PullCardIconInliner
{
    public function __construct(
        private readonly FilterService $filterService,
        #[Autowire('%games.liip_web_root%')]
        private readonly string $webRoot,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @return ?string null when the icon cannot be loaded (the card shows a placeholder)
     */
    public function dataUri(string $iconPath): ?string
    {
        $filter = PullItemIconUrlBuilder::FILTER_NAME;

        try {
            $this->filterService->warmUpCache($iconPath, $filter);
            // Layout of the web_path resolver: <web root>/media/cache/<filter>/<path>
            $file = sprintf('%s/media/cache/%s/%s', $this->webRoot, $filter, ltrim($iconPath, '/'));
            $content = is_file($file) ? file_get_contents($file) : false;
        } catch (\Throwable $e) {
            $this->logger?->warning('Pull card icon could not be loaded', ['path' => $iconPath, 'exception' => $e]);

            return null;
        }

        if ($content === false || $content === '') {
            $this->logger?->warning('Pull card icon missing from the cache', ['path' => $iconPath]);

            return null;
        }

        $mimeType = new \finfo(FILEINFO_MIME_TYPE)->buffer($content) ?: 'image/webp';

        return sprintf('data:%s;base64,%s', $mimeType, base64_encode($content));
    }
}
