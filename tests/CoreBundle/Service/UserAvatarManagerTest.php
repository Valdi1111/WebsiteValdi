<?php

namespace App\Tests\CoreBundle\Service;

use App\CoreBundle\Entity\User;
use App\CoreBundle\Exception\InvalidAvatarException;
use App\CoreBundle\Service\UserAvatarManager;
use App\Tests\CoreBundle\InMemoryUserAvatarRepository;
use App\Tests\CoreBundle\TestImages;
use Liip\ImagineBundle\Imagine\Cache\CacheManager;
use Liip\ImagineBundle\Service\FilterService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/**
 * Order of the writes when replacing or removing an avatar, with the real normalization (LiipImagine FilterManager)
 * and an in-memory repository: nothing is saved for an image that cannot be used, the user's row is saved before the
 * public copy of the previous image is deleted, and a failing cleanup never turns a saved change into an error.
 */
class UserAvatarManagerTest extends KernelTestCase
{
    private const string STORED_NAME = '/^[0-9a-f]{32}$/';

    private string $dir;
    private InMemoryUserAvatarRepository $avatars;
    private User $user;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->dir = sys_get_temp_dir().'/avatar-manager-test-'.bin2hex(random_bytes(4));
        (new Filesystem())->mkdir($this->dir);
        $this->avatars = new InMemoryUserAvatarRepository();
        $this->user = self::createUser(42);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
        parent::tearDown();
    }

    private static function createUser(int $id): User
    {
        $user = (new User())->setEmail('user'.$id.'@example.test');
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);

        return $user;
    }

    private function manager(?CacheManager $cacheManager = null, ?FilterService $filterService = null, ?LoggerInterface $logger = null): UserAvatarManager
    {
        return new UserAvatarManager(
            $this->avatars,
            static::getContainer()->get('liip_imagine.filter.manager'),
            $filterService ?? $this->createStub(FilterService::class),
            $cacheManager ?? $this->createStub(CacheManager::class),
            new LockFactory(new InMemoryStore()),
            $logger,
        );
    }

    private function png(): File
    {
        return new File(TestImages::image($this->dir, 'png', 200, 200));
    }

    private function storedName(): ?string
    {
        return $this->avatars->findNameByUser($this->user);
    }

    public function testFirstUploadCreatesTheUsersRowThenMakesThePublicCopy(): void
    {
        $filterService = $this->createMock(FilterService::class);
        $filterService->expects($this->once())->method('warmUpCache')
            ->with($this->matchesRegularExpression(self::STORED_NAME), UserAvatarManager::FILTER)
            ->willReturnCallback(function (string $name): bool {
                // Made from the saved row
                $this->assertSame($name, $this->storedName());

                return true;
            });
        $cacheManager = $this->createMock(CacheManager::class);
        $cacheManager->expects($this->never())->method('remove');

        $this->manager($cacheManager, $filterService)->replace($this->user, $this->png());

        [$row] = $this->avatars->rows();
        $this->assertSame($this->user, $row->getUser());
        $this->assertMatchesRegularExpression(self::STORED_NAME, (string) $row->getName());
        $this->assertSame([200, 200, 'image/webp'], TestImages::describe((string) $row->getContent()));
    }

    public function testNewUploadUpdatesTheSameRowThenDeletesThePreviousPublicCopy(): void
    {
        $this->manager()->replace($this->user, $this->png());
        [$first] = $this->avatars->rows();
        $cacheManager = $this->createMock(CacheManager::class);
        $cacheManager->expects($this->once())->method('remove')->with($first->getName(), UserAvatarManager::FILTER)
            ->willReturnCallback(function () use ($first): void {
                // The user already points to the new image
                $this->assertNotSame($first->getName(), $this->storedName());
            });

        $this->manager($cacheManager)->replace($this->user, $this->png());

        // The same row: its primary key is the user's id
        [$second] = $this->avatars->rows();
        $this->assertSame($this->user, $second->getUser());
        $this->assertNotSame($first->getName(), $second->getName());
        $this->assertSame(['save '.$first->getName(), 'save '.$second->getName()], $this->avatars->log);
    }

    public function testFailedSaveKeepsThePreviousAvatar(): void
    {
        $this->manager()->replace($this->user, $this->png());
        $previous = $this->storedName();
        $this->avatars->failNextSave(new \RuntimeException('Database down'));
        $cacheManager = $this->createMock(CacheManager::class);
        $cacheManager->expects($this->never())->method('remove');
        $filterService = $this->createMock(FilterService::class);
        $filterService->expects($this->never())->method('warmUpCache');

        try {
            $this->manager($cacheManager, $filterService)->replace($this->user, $this->png());
            $this->fail('The save error must reach the caller.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Database down', $e->getMessage());
        }

        $this->assertSame($previous, $this->storedName());
    }

    public function testUndecodableImageIsRejectedBeforeAnythingIsSaved(): void
    {
        try {
            $this->manager()->replace($this->user, new File(TestImages::truncatedPng($this->dir)));
            $this->fail('An image that cannot be decoded must be rejected.');
        } catch (InvalidAvatarException $e) {
            $this->assertSame('The image cannot be read.', $e->getMessage());
        }

        $this->assertSame([], $this->avatars->log);
    }

    public function testAnimatedImageIsRejectedBeforeAnythingIsSaved(): void
    {
        try {
            $this->manager()->replace($this->user, new File(TestImages::animatedWebp($this->dir)));
            $this->fail('An animated image must be rejected.');
        } catch (InvalidAvatarException $e) {
            $this->assertSame('Animated images are not supported.', $e->getMessage());
        }

        $this->assertSame([], $this->avatars->log);
    }

    public function testFailingPublicCopiesAreOnlyLogged(): void
    {
        $this->manager()->replace($this->user, $this->png());
        $filterService = $this->createStub(FilterService::class);
        $filterService->method('warmUpCache')->willThrowException(new \RuntimeException('Disk full'));
        $cacheManager = $this->createStub(CacheManager::class);
        $cacheManager->method('remove')->willThrowException(new \RuntimeException('Permission denied'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->exactly(2))->method('warning');

        $this->manager($cacheManager, $filterService, $logger)->replace($this->user, $this->png());

        $this->assertCount(2, $this->avatars->log);
    }

    public function testRemoveDeletesTheRowThenThePublicCopy(): void
    {
        $this->manager()->replace($this->user, $this->png());
        $name = $this->storedName();
        $cacheManager = $this->createMock(CacheManager::class);
        $cacheManager->expects($this->once())->method('remove')->with($name, UserAvatarManager::FILTER)
            ->willReturnCallback(function (): void {
                $this->assertSame([], $this->avatars->rows());
                // A failing cleanup does not turn the saved change into an error
                throw new \RuntimeException('Permission denied');
            });

        $this->manager($cacheManager)->remove($this->user);

        $this->assertNull($this->storedName());
    }

    public function testRemoveWithoutAvatarDoesNothing(): void
    {
        $cacheManager = $this->createMock(CacheManager::class);
        $cacheManager->expects($this->never())->method('remove');

        $this->manager($cacheManager)->remove($this->user);

        $this->assertSame([], $this->avatars->log);
    }

    public function testUrlIsTheFilterSetPathOfTheStoredName(): void
    {
        $this->manager()->replace($this->user, $this->png());
        $name = (string) $this->storedName();
        $cacheManager = $this->createMock(CacheManager::class);
        $cacheManager->expects($this->once())->method('getBrowserPath')->with($name, UserAvatarManager::FILTER)->willReturn('/media/cache/core_avatar/'.$name);

        $this->assertSame('/media/cache/core_avatar/'.$name, $this->manager($cacheManager)->getUrl($this->user));
        $this->assertNull($this->manager()->getUrl(self::createUser(43)));
    }
}
