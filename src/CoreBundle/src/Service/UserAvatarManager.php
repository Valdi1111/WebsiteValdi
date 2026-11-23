<?php

namespace App\CoreBundle\Service;

use App\CoreBundle\Entity\User;
use App\CoreBundle\Entity\UserAvatar;
use App\CoreBundle\Exception\InvalidAvatarException;
use App\CoreBundle\Repository\UserAvatarRepository;
use Imagine\Exception\Exception as ImagineException;
use Liip\ImagineBundle\Imagine\Cache\CacheManager;
use Liip\ImagineBundle\Imagine\Filter\FilterManager;
use Liip\ImagineBundle\Model\FileBinary;
use Liip\ImagineBundle\Service\FilterService;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Lock\LockFactory;

/**
 * Profile image of a user, stored in the database (UserAvatar, an extension of the user row) and shown through the "core_avatar" LiipImagine
 * filter set, whose cached copy is a public file.
 *
 * The upload is normalized before it is stored (one frame, EXIF orientation applied, no metadata, at most 512 px,
 * webp): an image that cannot be decoded is rejected there, before it replaces a working avatar. The user's row is
 * then updated in one flush, so the database never points to a missing image; only the public copy of the previous
 * image is a file, deleted afterwards.
 */
class UserAvatarManager
{
    public const string FILTER = 'core_avatar';
    public const string NAME_PATTERN = '/^[0-9a-f]{32}$/';
    public const string STORED_FORMAT = 'webp';
    public const string STORED_MIME_TYPE = 'image/webp';

    /**
     * Applied once on upload (not a filter set: it would get a public resolve route). The stored image is the source
     * of the "core_avatar" filter set, so it keeps room for a larger display than 256 px.
     */
    private const array NORMALIZATION = [
        'filters' => [
            'auto_rotate' => [],
            'strip' => [],
            'thumbnail' => ['size' => [512, 512], 'mode' => 'inset', 'allow_upscale' => false],
        ],
        'format' => self::STORED_FORMAT,
        'quality' => 90,
    ];

    public function __construct(
        private readonly UserAvatarRepository $repository,
        private readonly FilterManager $filterManager,
        private readonly FilterService $filterService,
        private readonly CacheManager $cacheManager,
        private readonly LockFactory $lockFactory,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @return string|null URL of the resized image, null when the user has none (the frontend shows a default one)
     */
    public function getUrl(User $user): ?string
    {
        $name = $this->repository->findNameByUser($user);

        return $name === null ? null : $this->cacheManager->getBrowserPath($name, self::FILTER);
    }

    /**
     * Stores the image (type, size and dimensions already validated) as the user's avatar, under a new random name.
     *
     * @throws InvalidAvatarException when the image is animated or cannot be decoded; nothing is changed then
     */
    public function replace(User $user, File $image): void
    {
        $content = $this->normalize($image);

        $this->withUserLock($user, function () use ($user, $content): void {
            $avatar = $this->repository->findOneByUser($user) ?? new UserAvatar($user);
            $previous = $avatar->getName();

            $avatar->setName(bin2hex(random_bytes(16)))
                ->setContent($content)
                ->setUpdatedAt(new \DateTimeImmutable());
            $this->repository->save($avatar);

            if ($previous !== null) {
                $this->removeResizedCopy($previous);
            }
            $this->warmUp((string) $avatar->getName());
        });
    }

    /**
     * Goes back to the default image.
     */
    public function remove(User $user): void
    {
        $this->withUserLock($user, function () use ($user): void {
            $avatar = $this->repository->findOneByUser($user);
            if ($avatar === null) {
                return;
            }

            $name = (string) $avatar->getName();
            $this->repository->delete($avatar);
            $this->removeResizedCopy($name);
        });
    }

    /**
     * @return string the normalized image (webp)
     *
     * @throws InvalidAvatarException
     */
    private function normalize(File $image): string
    {
        $this->assertSingleFrame($image);

        try {
            $binary = $this->filterManager->applyFilters(new FileBinary($image->getPathname(), (string) $image->getMimeType()), self::NORMALIZATION);
        } catch (ImagineException $e) {
            throw new InvalidAvatarException('The image cannot be read.', 0, $e);
        }

        return $binary->getContent();
    }

    /**
     * Animated images (several frames) are refused: decoding would load every frame. Pinging reads the frame count
     * without decoding the pixels.
     */
    private function assertSingleFrame(File $image): void
    {
        try {
            $imagick = new \Imagick();
            $imagick->pingImage($image->getPathname());
            $frames = $imagick->getNumberImages();
            $imagick->clear();
        } catch (\ImagickException $e) {
            throw new InvalidAvatarException('The image cannot be read.', 0, $e);
        }

        if ($frames !== 1) {
            throw new InvalidAvatarException('Animated images are not supported.');
        }
    }

    /**
     * One change at a time per user: two first uploads at once would both insert a row (the primary key refuses the
     * second), and two replacements would both read the same previous name and leave a public copy behind.
     *
     * @param \Closure(): void $change
     */
    private function withUserLock(User $user, \Closure $change): void
    {
        $lock = $this->lockFactory->createLock('core_avatar_user_'.$user->getId());
        $lock->acquire(true);
        try {
            $change();
        } finally {
            $lock->release();
        }
    }

    /**
     * Makes the public copy now, so that the URL returned by getUrl() is a ready file. A failure is only logged:
     * the LiipImagine resolve route makes it on the first request.
     */
    private function warmUp(string $name): void
    {
        try {
            $this->filterService->warmUpCache($name, self::FILTER);
        } catch (\Throwable $e) {
            $this->logger?->warning('Could not make the resized avatar', ['avatar' => $name, 'error' => $e->getMessage()]);
        }
    }

    /**
     * The change is already saved: a failure only leaves an orphan public copy, which is logged.
     */
    private function removeResizedCopy(string $name): void
    {
        try {
            $this->cacheManager->remove($name, self::FILTER);
        } catch (\Throwable $e) {
            $this->logger?->warning('Could not delete the resized avatar', ['avatar' => $name, 'error' => $e->getMessage()]);
        }
    }
}
