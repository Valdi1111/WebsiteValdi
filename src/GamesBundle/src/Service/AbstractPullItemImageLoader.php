<?php

namespace App\GamesBundle\Service;

use App\GamesBundle\Model\Game\PullItemIconQuery;
use Liip\ImagineBundle\Binary\BinaryInterface;
use Liip\ImagineBundle\Binary\Loader\LoaderInterface;
use Liip\ImagineBundle\Exception\Binary\Loader\NotLoadableException;
use Liip\ImagineBundle\Model\Binary;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * LiipImagine data loader of the pull item images: decodes the path built by PullItemIconPathCodec and downloads
 * the source image from the game CDNs. Subclasses choose the source URLs (icon or full artwork).
 */
abstract class AbstractPullItemImageLoader implements LoaderInterface
{
    private const array MIME_TO_EXTENSION = [
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/jpeg' => 'jpg',
        'image/jpg' => 'jpg',
    ];

    /** Statuses meaning that the image does not exist, as opposed to a failure worth retrying. */
    private const array MISSING_STATUSES = [404, 410];

    public function __construct(
        private readonly PullItemIconPathCodec $pathCodec,
        protected readonly PullItemIconResolverManager $resolverManager,
        #[Target('games.pull_icon_fetch.client')]
        private readonly HttpClientInterface $httpClient,
        protected readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function find($path): BinaryInterface
    {
        $decoded = $this->pathCodec->decode((string) $path);
        if ($decoded === null) {
            throw new NotLoadableException(sprintf('Pull item image path "%s" is invalid.', (string) $path));
        }

        return $this->load($decoded['gameBiz'], $decoded['query'], (string) $path);
    }

    /**
     * @throws NotLoadableException when no image can be loaded: LiipImagine then caches nothing
     */
    abstract protected function load(string $gameBiz, PullItemIconQuery $query, string $path): BinaryInterface;

    /**
     * Downloads the first candidate answering with an image.
     *
     * @param list<string> $urls
     * @return array{0: ?BinaryInterface, 1: bool} the image (null when none could be downloaded), and whether every
     *                                             candidate answered that it does not exist (404/410)
     */
    protected function download(array $urls, string $path): array
    {
        $allMissing = true;

        foreach ($urls as $url) {
            try {
                $response = $this->httpClient->request('GET', $url);
                $status = $response->getStatusCode();
                if ($status !== 200) {
                    $allMissing = $allMissing && in_array($status, self::MISSING_STATUSES, true);
                    $this->logger?->debug('Pull item image CDN returned non-200 status', [
                        'url' => $url,
                        'status' => $status,
                    ]);
                    continue;
                }

                $allMissing = false;
                $headers = $response->getHeaders(false);
                $rawContentType = $headers['content-type'][0] ?? '';
                $mimeType = strtolower(trim(explode(';', $rawContentType)[0]));

                if ($mimeType === '' || !str_starts_with($mimeType, 'image/')) {
                    $mimeType = $this->guessMimeFromUrl($url);
                }

                if ($mimeType === null || !str_starts_with($mimeType, 'image/')) {
                    $this->logger?->warning('Pull item image response is not an image', [
                        'url' => $url,
                        'content_type' => $rawContentType,
                    ]);
                    continue;
                }

                $content = $response->getContent();
                if ($content === '') {
                    continue;
                }

                $extension = self::MIME_TO_EXTENSION[$mimeType] ?? pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'png';

                return [new Binary($content, $mimeType, $extension), false];
            } catch (\Throwable $e) {
                $allMissing = false;
                $this->logger?->warning('Failed downloading pull item image from CDN', [
                    'url' => $url,
                    'path' => $path,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [null, $allMissing];
    }

    private function guessMimeFromUrl(string $url): ?string
    {
        $ext = strtolower((string) pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));

        return match ($ext) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            'jpg', 'jpeg' => 'image/jpeg',
            default => null,
        };
    }
}
