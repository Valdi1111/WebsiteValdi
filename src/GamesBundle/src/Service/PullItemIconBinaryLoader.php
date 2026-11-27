<?php

namespace App\GamesBundle\Service;

use App\GamesBundle\Model\Game\PullItemIconQuery;
use Liip\ImagineBundle\Binary\BinaryInterface;
use Liip\ImagineBundle\Exception\Binary\Loader\NotLoadableException;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Source of the pull item icons (filter set games_pull_item_icon): the icon candidates of the resolvers, by priority.
 */
#[AutoconfigureTag('liip_imagine.binary.loader', attributes: ['loader' => 'games.pull_item_icon_loader'])]
class PullItemIconBinaryLoader extends AbstractPullItemImageLoader
{
    protected function load(string $gameBiz, PullItemIconQuery $query, string $path): BinaryInterface
    {
        $candidateUrls = $this->resolverManager->resolveCandidateUrlsByGameBiz($gameBiz, $query);
        if ($candidateUrls === []) {
            throw new NotLoadableException(sprintf('No pull item icon URL resolved for "%s".', $path));
        }

        [$binary] = $this->download($candidateUrls, $path);

        return $binary ?? throw new NotLoadableException(sprintf('Pull item icon could not be downloaded for "%s".', $path));
    }
}
