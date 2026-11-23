<?php

namespace App\Tests\CoreBundle\Controller;

use App\CoreBundle\Entity\User;
use App\Tests\CoreBundle\TestImages;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST and DELETE /api/me/avatar through the whole HTTP stack, without a database (the profile images are kept by
 * an in-memory UserAvatarRepository), with the real LiipImagine filters; the public copies go under the test cache
 * dir (when@test in config/packages/liip_imagine.yaml).
 */
class ApiMeAvatarTest extends WebTestCase
{
    use WithoutDatabaseClientTrait;

    private const string STORED_NAME = '/^[0-9a-f]{32}$/';

    private string $imagesDir;

    protected function setUp(): void
    {
        $this->roles['ROLE_USER'] = self::role('ROLE_USER', 'User', null);
        $this->imagesDir = sys_get_temp_dir().'/avatar-test-'.bin2hex(random_bytes(4));
        (new Filesystem())->mkdir($this->imagesDir);
    }

    protected function tearDown(): void
    {
        $filesystem = new Filesystem();
        $filesystem->remove($this->imagesDir);
        if (static::$booted) {
            $filesystem->remove(self::resizedDir());
        }
        parent::tearDown();
    }

    private static function resizedDir(): string
    {
        return static::getContainer()->getParameter('kernel.cache_dir').'/public/media/cache/core_avatar';
    }

    /**
     * @return list<string> names of the public copies
     */
    private static function resizedCopies(): array
    {
        return is_dir(self::resizedDir()) ? array_values(array_diff((array) scandir(self::resizedDir()), ['.', '..'])) : [];
    }

    private function uploaded(string $path): UploadedFile
    {
        // The client name is ignored: it must never become the stored one
        return new UploadedFile($path, '../../photo.'.pathinfo($path, PATHINFO_EXTENSION), null, null, true);
    }

    private function image(string $format, int $width, int $height): UploadedFile
    {
        return $this->uploaded(TestImages::image($this->imagesDir, $format, $width, $height));
    }

    /**
     * @param UploadedFile|list<UploadedFile>|null $image
     */
    private function upload(KernelBrowser $client, UploadedFile|array|null $image): void
    {
        $client->request('POST', self::url('games.domain_name', '/api/me/avatar'), [], $image === null ? [] : ['avatar' => $image]);
    }

    private function storedName(User $user): string
    {
        $name = (string) $this->avatars->findNameByUser($user);
        $this->assertMatchesRegularExpression(self::STORED_NAME, $name);

        return $name;
    }

    /**
     * The URL of the public copy made during the upload: a ready file, not the resolve route.
     */
    private function assertAvatarUrl(?string $name, KernelBrowser $client): void
    {
        $url = self::jsonResponse($client)['avatar_url'];
        if ($name === null) {
            $this->assertNull($url);

            return;
        }
        $this->assertSame('https://'.static::getContainer()->getParameter('games.domain_name').'/media/cache/core_avatar/'.$name, $url);
    }

    public function testUploadStoresTheNormalizedImageAndMakesItsPublicCopy(): void
    {
        $client = $this->createClientWithoutDatabase();
        $user = $this->createUser(['ROLE_USER']);
        $this->logIn($client, $user);

        $this->upload($client, $this->image('jpeg', 800, 300));

        $this->assertResponseIsSuccessful();
        $name = $this->storedName($user);
        $this->assertAvatarUrl($name, $client);
        $this->assertSame(['id', 'email', 'roles', 'avatar_url'], array_keys(self::jsonResponse($client)));

        [$row] = $this->avatars->rows();
        $this->assertSame($user, $row->getUser());
        // Stored: at most 512 px, webp. Public copy: the "core_avatar" square centre crop
        $this->assertSame([512, 192, 'image/webp'], TestImages::describe((string) $row->getContent()));
        $this->assertSame([256, 256, 'image/webp'], TestImages::describe((string) file_get_contents(self::resizedDir().'/'.$name)));
    }

    public function testUploadAppliesTheExifOrientationAndDropsTheMetadata(): void
    {
        $client = $this->createClientWithoutDatabase();
        $user = $this->createUser(['ROLE_USER']);
        $this->logIn($client, $user);

        $this->upload($client, $this->uploaded(TestImages::jpegWithOrientation($this->imagesDir, 400, 200, 6)));

        $this->assertResponseIsSuccessful();
        $stored = (string) $this->avatars->findContentByName($this->storedName($user));
        // Turned upright (portrait), and no EXIF left (GPS position included)
        $this->assertSame([200, 400, 'image/webp'], TestImages::describe($stored));
        $this->assertSame([], TestImages::exif($stored));
    }

    public function testNewUploadReplacesThePreviousImage(): void
    {
        $client = $this->createClientWithoutDatabase();
        $user = $this->createUser(['ROLE_USER']);
        $this->logIn($client, $user);
        $this->upload($client, $this->image('png', 300, 300));
        $first = $this->storedName($user);

        $this->upload($client, $this->image('webp', 400, 400));

        $this->assertResponseIsSuccessful();
        $second = $this->storedName($user);
        $this->assertNotSame($first, $second);
        // Still one row per user, and only the public copy of the new image
        $this->assertCount(1, $this->avatars->rows());
        $this->assertSame([$second], self::resizedCopies());
        $this->assertAvatarUrl($second, $client);
    }

    public function testDeleteGoesBackToTheDefaultImage(): void
    {
        $client = $this->createClientWithoutDatabase();
        $user = $this->createUser(['ROLE_USER']);
        $this->logIn($client, $user);
        $this->upload($client, $this->image('png', 300, 300));

        $client->request('DELETE', self::url('games.domain_name', '/api/me/avatar'));

        $this->assertResponseIsSuccessful();
        $this->assertSame([], $this->avatars->rows());
        $this->assertSame([], self::resizedCopies());
        $this->assertAvatarUrl(null, $client);
    }

    /**
     * @return iterable<string, array{\Closure(self): (UploadedFile|list<UploadedFile>|null), string}>
     */
    public static function invalidUploads(): iterable
    {
        yield 'no file' => [static fn (self $test): ?UploadedFile => null, 'avatar: Choose an image to upload.'];
        yield 'a list of files' => [static fn (self $test): array => [$test->image('png', 300, 300)], 'avatar: Choose an image to upload.'];
        yield 'GIF' => [static fn (self $test): UploadedFile => $test->image('gif', 300, 300), 'avatar: The image must be a JPEG, PNG or WebP file.'];
        yield 'too small' => [static fn (self $test): UploadedFile => $test->image('png', 100, 300), 'avatar: The image width is too small (100px). Minimum width expected is 128px.'];
        yield 'too large' => [static fn (self $test): UploadedFile => $test->image('png', 300, 5000), 'avatar: The image height is too big (5000px). Allowed maximum height is 4096px.'];
        // Valid header: only decoding the image finds them
        yield 'truncated' => [static fn (self $test): UploadedFile => $test->uploaded(TestImages::truncatedPng($test->imagesDir)), 'avatar: The image cannot be read.'];
        yield 'animated' => [static fn (self $test): UploadedFile => $test->uploaded(TestImages::animatedWebp($test->imagesDir)), 'avatar: Animated images are not supported.'];
    }

    /**
     * @param \Closure(self): (UploadedFile|list<UploadedFile>|null) $image
     */
    #[DataProvider('invalidUploads')]
    public function testInvalidUploadIsRejectedWithAReadableDetailAndKeepsTheAvatar(\Closure $image, string $detail): void
    {
        $client = $this->createClientWithoutDatabase();
        $user = $this->createUser(['ROLE_USER']);
        $this->logIn($client, $user);
        $this->upload($client, $this->image('png', 300, 300));
        $previous = $this->storedName($user);

        $this->upload($client, $image($this));

        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        // What the frontend shows (withErrorHandling / withLoadingMessage read "detail"), as Symfony formats violations
        $this->assertSame($detail, self::jsonResponse($client)['detail'] ?? null);
        $this->assertSame($previous, $this->storedName($user));
        $this->assertCount(1, $this->avatars->rows());
        $this->assertSame([$previous], self::resizedCopies());
    }

    public function testUploadNeedsALoggedInUser(): void
    {
        $client = $this->createClientWithoutDatabase();

        $this->upload($client, $this->image('png', 300, 300));

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        $this->assertSame([], $this->avatars->rows());
    }

    public function testUserWithoutAvatarHasNoUrl(): void
    {
        $client = $this->createClientWithoutDatabase();
        $this->logIn($client, $this->createUser(['ROLE_USER']));

        $client->request('GET', self::url('games.domain_name', '/api/me'));

        $this->assertResponseIsSuccessful();
        $this->assertAvatarUrl(null, $client);
    }
}
