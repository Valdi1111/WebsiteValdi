<?php

namespace App\GamesBundle\Service\Pull\Card;

use App\GamesBundle\Exception\PullCardUnavailableException;
use App\GamesBundle\Model\Pull\Card\PullCard;
use App\GamesBundle\Model\Pull\Card\PullCardImage;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Twig\Environment;

/**
 * Turns a pulls card into a PNG: a self-contained HTML document (icons and font inlined) screenshotted by the
 * headless Chromium of node-services, which runs no script and lets no request out.
 */
class PullCardRenderer
{
    public const int WIDTH = 1200;
    public const int SCALE = 2;
    /** Largest side a WebP can have */
    public const int WEBP_MAX_SIDE = 16383;
    private const int WEBP_QUALITY = 85;
    /** Limits of the call to node-services (on loopback): connection, idle time, whole render (a big one takes ~5 s) */
    private const float CONNECT_TIMEOUT = 3.0;
    private const float TIMEOUT = 20.0;
    private const float MAX_DURATION = 45.0;

    /** Ubuntu (UFL 1.0, resources/fonts/ubuntu): the subsets embedded, with the code points each one covers */
    private const string FONT_DIR = __DIR__.'/../../../../resources/fonts/ubuntu';
    private const array FONT_SUBSETS = [
        'latin' => 'U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+0304,U+0308,U+0329,U+2000-206F,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD',
        'latin-ext' => 'U+0100-02BA,U+02BD-02C5,U+02C7-02CC,U+02CE-02D7,U+02DD-02FF,U+0304,U+0308,U+0329,U+1D00-1DBF,U+1E00-1E9F,U+1EF2-1EFF,U+2020,U+20A0-20AB,U+20AD-20C0,U+2113,U+2C60-2C7F,U+A720-A7FF',
    ];
    private const array FONT_WEIGHTS = [400, 500, 700];

    /** @var list<array{weight: int, range: string, src: string}>|null */
    private ?array $fontFaces = null;

    public function __construct(
        private readonly Environment $twig,
        private readonly PullCardIconInliner $iconInliner,
        #[Target('node_services.client')]
        private readonly HttpClientInterface $nodeServices,
        private readonly SluggerInterface $slugger,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function renderHtml(PullCard $card): string
    {
        $icons = [];
        foreach ($card->sections as $section) {
            foreach ($section->items as $item) {
                if ($item->iconPath !== null && !array_key_exists($item->iconPath, $icons)) {
                    $icons[$item->iconPath] = $this->iconInliner->dataUri($item->iconPath);
                }
            }
        }

        return $this->twig->render('@Games/pulls/card.html.twig', [
            'card' => $card,
            'icons' => $icons,
            'font_faces' => $this->getFontFaces(),
            'width' => self::WIDTH,
        ]);
    }

    /**
     * @param int $scale device pixels per CSS pixel: the PNG is WIDTH × scale pixels wide
     *
     * @return string the PNG bytes
     *
     * @throws PullCardUnavailableException when node-services cannot render it
     */
    public function renderPng(PullCard $card, int $scale = self::SCALE): string
    {
        $html = $this->renderHtml($card);

        try {
            $response = $this->nodeServices->request('POST', '/render/png', [
                'json' => [
                    'html' => $html,
                    'width' => self::WIDTH,
                    'scale' => $scale,
                    'selector' => '#card',
                ],
                'max_connect_duration' => self::CONNECT_TIMEOUT,
                'timeout' => self::TIMEOUT,
                'max_duration' => self::MAX_DURATION,
            ]);

            $status = $response->getStatusCode();
            if ($status !== 200) {
                $this->logger?->error('node-services refused the pulls card (HTTP {status}): {body}', [
                    'status' => $status,
                    'body' => mb_substr($response->getContent(false), 0, 500),
                ]);

                throw new PullCardUnavailableException();
            }

            return $response->getContent();
        } catch (HttpClientExceptionInterface $e) {
            $this->logger?->error('node-services could not be reached for the pulls card: {error}', ['error' => $e->getMessage()]);

            throw new PullCardUnavailableException(previous: $e);
        }
    }

    /**
     * The card as a WebP (lossy, much lighter than the PNG), for the chat platforms. A card taller than a WebP can be
     * at this scale is rendered again at 1x.
     *
     * @throws PullCardUnavailableException when node-services cannot render it, or the card is too tall even at 1x
     */
    public function renderWebp(PullCard $card, int $scale = self::SCALE): PullCardImage
    {
        $png = $this->renderPng($card, $scale);
        // The size from the PNG header: a too large image is never decoded (ImageMagick may refuse it by policy)
        $size = @getimagesizefromstring($png);
        if ($size !== false && max($size[0], $size[1]) > self::WEBP_MAX_SIDE) {
            if ($scale > 1) {
                return $this->renderWebp($card, 1);
            }

            throw new PullCardUnavailableException();
        }

        $image = new \Imagick();
        try {
            $image->readImageBlob($png);
            $width = $image->getImageWidth();
            $height = $image->getImageHeight();
            $image->setImageFormat('webp');
            $image->setImageCompressionQuality(self::WEBP_QUALITY);

            return new PullCardImage($image->getImagesBlob(), 'image/webp', 'webp', $width, $height);
        } catch (\ImagickException $e) {
            $this->logger?->error('The pulls card could not be converted to WebP: {error}', ['error' => $e->getMessage()]);

            throw new PullCardUnavailableException(previous: $e);
        } finally {
            $image->clear();
        }
    }

    /**
     * File name of the card, after the game and its title (e.g. genshin-impact-all-banners.png).
     */
    public function filename(PullCard $card, string $extension): string
    {
        return $this->slugger->slug(sprintf('%s %s', $card->gameName, $card->title))->lower()->toString().'.'.$extension;
    }

    /**
     * The PNG as a download, named after the game and the title of the card (e.g. genshin-impact-all-banners.png).
     *
     * @throws PullCardUnavailableException when node-services cannot render it
     */
    public function renderResponse(PullCard $card): Response
    {
        $response = new Response($this->renderPng($card), Response::HTTP_OK, ['Content-Type' => 'image/png']);

        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $this->filename($card, 'png')));
        // Built for one user, from live data
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }

    /**
     * @return list<array{weight: int, range: string, src: string}>
     */
    private function getFontFaces(): array
    {
        if ($this->fontFaces !== null) {
            return $this->fontFaces;
        }

        $faces = [];
        foreach (self::FONT_SUBSETS as $subset => $range) {
            foreach (self::FONT_WEIGHTS as $weight) {
                $file = sprintf('%s/ubuntu-%s-%d-normal.woff2', self::FONT_DIR, $subset, $weight);
                $content = is_file($file) ? file_get_contents($file) : false;
                if ($content === false) {
                    // The card falls back on the system fonts
                    $this->logger?->warning('Pulls card font missing: {file}', ['file' => $file]);
                    continue;
                }
                $faces[] = ['weight' => $weight, 'range' => $range, 'src' => 'data:font/woff2;base64,'.base64_encode($content)];
            }
        }

        return $this->fontFaces = $faces;
    }
}
