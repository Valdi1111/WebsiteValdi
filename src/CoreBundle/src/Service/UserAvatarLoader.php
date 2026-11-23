<?php

namespace App\CoreBundle\Service;

use App\CoreBundle\Repository\UserAvatarRepository;
use Liip\ImagineBundle\Binary\BinaryInterface;
use Liip\ImagineBundle\Binary\Loader\LoaderInterface;
use Liip\ImagineBundle\Exception\Binary\Loader\NotLoadableException;
use Liip\ImagineBundle\Model\Binary;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Data loader of the "core_avatar" filter set: the normalized profile image (webp) stored in the database.
 */
#[AutoconfigureTag('liip_imagine.binary.loader', attributes: ['loader' => 'core_avatar'])]
class UserAvatarLoader implements LoaderInterface
{
    public function __construct(private readonly UserAvatarRepository $repository)
    {
    }

    public function find($path): BinaryInterface
    {
        $name = (string) $path;
        // Only the random names UserAvatarManager generates: anything else is not a query worth running
        if (preg_match(UserAvatarManager::NAME_PATTERN, $name) !== 1) {
            throw new NotLoadableException(sprintf('Invalid avatar name "%s".', $name));
        }

        $content = $this->repository->findContentByName($name);
        if ($content === null) {
            throw new NotLoadableException(sprintf('Avatar "%s" not found.', $name));
        }

        return new Binary($content, UserAvatarManager::STORED_MIME_TYPE, UserAvatarManager::STORED_FORMAT);
    }
}
