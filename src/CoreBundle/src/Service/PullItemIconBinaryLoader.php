<?php

namespace App\CoreBundle\Service;

use Liip\ImagineBundle\Binary\BinaryInterface;
use Liip\ImagineBundle\Binary\Loader\LoaderInterface;
use Liip\ImagineBundle\Exception\Binary\Loader\NotLoadableException;
use Liip\ImagineBundle\Model\Binary;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AutoconfigureTag('liip_imagine.binary.loader', attributes: ['loader' => 'core.pull_item_icon_loader'])]
class PullItemIconBinaryLoader implements LoaderInterface
{
    private const array MIME_TO_EXTENSION = [
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/jpeg' => 'jpg',
        'image/jpg' => 'jpg',
    ];

    public function __construct(
        private readonly PullItemIconPathCodec $pathCodec,
        private readonly PullItemIconResolverManager $resolverManager,
        #[Target('core.pull_icon_fetch.client')]
        private readonly HttpClientInterface $httpClient,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function find($path): BinaryInterface
    {
        $decoded = $this->pathCodec->decode((string) $path);
        if ($decoded === null) {
            throw new NotLoadableException(sprintf('Pull item icon path "%s" is invalid.', (string) $path));
        }

        $candidateUrls = $this->resolverManager->resolveCandidateUrlsByGameBiz($decoded['gameBiz'], $decoded['query']);
        if ($candidateUrls === []) {
            throw new NotLoadableException(sprintf('No pull item icon URL resolved for "%s".', (string) $path));
        }

        foreach ($candidateUrls as $url) {
            try {
                $response = $this->httpClient->request('GET', $url);
                $status = $response->getStatusCode();
                if ($status !== 200) {
                    $this->logger?->debug('Pull item icon CDN returned non-200 status', [
                        'url' => $url,
                        'status' => $status,
                    ]);
                    continue;
                }

                $headers = $response->getHeaders(false);
                $rawContentType = $headers['content-type'][0] ?? '';
                $mimeType = strtolower(trim(explode(';', $rawContentType)[0]));

                if ($mimeType === '' || !str_starts_with($mimeType, 'image/')) {
                    $mimeType = $this->guessMimeFromUrl($url);
                }

                if ($mimeType === null || !str_starts_with($mimeType, 'image/')) {
                    $this->logger?->warning('Pull item icon response is not an image', [
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

                return new Binary($content, $mimeType, $extension);
            } catch (\Throwable $e) {
                $this->logger?->warning('Failed downloading pull item icon from CDN', [
                    'url' => $url,
                    'path' => (string) $path,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        throw new NotLoadableException(sprintf('Pull item icon could not be downloaded for "%s".', (string) $path));
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
