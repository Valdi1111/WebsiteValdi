<?php

namespace App\GamesBundle\Service;

use App\GamesBundle\Model\Game\PullItemIconQuery;
use Liip\ImagineBundle\Binary\BinaryInterface;
use Liip\ImagineBundle\Exception\Binary\Loader\NotLoadableException;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Source of the click-to-zoom previews (filter set games_pull_item_preview): the full artwork of the item, else its
 * icon source.
 *
 * LiipImagine caches whatever this loader returns for good, so the icon is used only when the item has no artwork
 * (no artwork URL, or every artwork URL answered 404/410). Any other failure (timeout, 5xx) throws, so that the
 * preview is retried on the next request instead of caching the icon in place of the artwork.
 */
#[AutoconfigureTag('liip_imagine.binary.loader', attributes: ['loader' => 'games.pull_item_preview_loader'])]
class PullItemPreviewBinaryLoader extends AbstractPullItemImageLoader
{
    protected function load(string $gameBiz, PullItemIconQuery $query, string $path): BinaryInterface
    {
        $previewUrls = $this->resolverManager->resolvePreviewCandidateUrlsByGameBiz($gameBiz, $query);
        if ($previewUrls !== []) {
            [$binary, $allMissing] = $this->download($previewUrls, $path);
            if ($binary !== null) {
                return $binary;
            }
            if (!$allMissing) {
                throw new NotLoadableException(sprintf('Pull item artwork could not be downloaded for "%s".', $path));
            }
        }

        $iconUrls = $this->resolverManager->resolveCandidateUrlsByGameBiz($gameBiz, $query);
        if ($iconUrls === []) {
            throw new NotLoadableException(sprintf('No pull item artwork or icon URL resolved for "%s".', $path));
        }

        [$binary] = $this->download($iconUrls, $path);

        return $binary ?? throw new NotLoadableException(sprintf('Pull item preview could not be downloaded for "%s".', $path));
    }
}
