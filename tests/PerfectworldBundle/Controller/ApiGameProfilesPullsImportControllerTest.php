<?php

namespace App\Tests\PerfectworldBundle\Controller;

use App\CoreBundle\Entity\User;
use App\PerfectworldBundle\Controller\ApiGameProfilesController;
use App\PerfectworldBundle\Entity\PerfectworldAccount;
use App\PerfectworldBundle\Entity\PerfectworldGameProfile;
use App\PerfectworldBundle\Message\Profile\ImportPullsBackupMessage;
use App\PerfectworldBundle\Repository\PerfectworldPullRepository;
use App\PerfectworldBundle\Service\Catalog\NteCatalog;
use App\PerfectworldBundle\Service\NevernessToEvernessService;
use App\PerfectworldBundle\Service\Pull\Importer\NteHistoryExportImporter;
use App\PerfectworldBundle\Service\Pull\ManualPullService;
use App\PerfectworldBundle\Service\Pull\PullBackupImporterManager;
use App\PerfectworldBundle\Service\Pull\PullBackupImportService;
use App\PerfectworldBundle\Service\Pull\PullOperationTracker;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Multi-file import endpoint: every file is validated before anything is queued, and rejected uploads
 * leave no temporary file behind.
 */
class ApiGameProfilesPullsImportControllerTest extends TestCase
{
    private const string UID = '100000000001';

    private string $projectDir;
    private PullOperationTracker $tracker;
    /** @var list<object> */
    private array $dispatched = [];

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/valdi_pw_ctrl_test_' . bin2hex(random_bytes(6));
        mkdir($this->projectDir, 0775, true);
        $this->tracker = new PullOperationTracker(new ArrayAdapter(), $this->createStub(HubInterface::class), 'games.test');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectDir);
    }

    private function removeDirectory(string $dir): void
    {
        foreach (is_dir($dir) ? scandir($dir) ?: [] : [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            is_dir("$dir/$entry") ? $this->removeDirectory("$dir/$entry") : @unlink("$dir/$entry");
        }
        @rmdir($dir);
    }

    /**
     * @return array{User, PerfectworldAccount, PerfectworldGameProfile}
     */
    private static function createOwner(): array
    {
        $user = new User();
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, 1);
        $account = (new PerfectworldAccount())->setUser($user);
        $profile = (new PerfectworldGameProfile())->setGameId(NevernessToEvernessService::GAME_ID)->setGameUid(self::UID)->setNickname('Player_1');
        $account->addGameProfile($profile);
        (new \ReflectionProperty(PerfectworldGameProfile::class, 'id'))->setValue($profile, 5);

        return [$user, $account, $profile];
    }

    private function createController(): ApiGameProfilesController
    {
        $game = new NevernessToEvernessService(new NullLogger());
        $controller = new ApiGameProfilesController(
            $this->createStub(EntityManagerInterface::class),
            new ServiceLocator([(string) NevernessToEvernessService::GAME_ID => static fn () => $game]),
            $this->projectDir,
        );

        $serializer = $this->createStub(SerializerInterface::class);
        $serializer->method('serialize')->willReturnCallback(static fn (mixed $data): string => json_encode($data, JSON_THROW_ON_ERROR));
        $container = new Container();
        $container->set('serializer', $serializer);
        $controller->setContainer($container);

        return $controller;
    }

    private function export(string $banner, string $uid = self::UID): string
    {
        $isBoard = NevernessToEvernessService::isBoardBanner($banner);
        $record = ['uid' => 'abc', 'timestamp' => '2026-06-01 10:00:00', 'reward_type' => 'arc', 'reward_id' => 'fork_vine', 'reward_name' => 'Be Happy', 'reward_rank' => 'B']
            + ($isBoard ? ['roll_result' => 3, 'result_type' => 'dice', 'quantity' => 1] : ['source_type' => 'miracle_box']);

        return json_encode([
            'format' => 'nte-history-export',
            'format_version' => 1,
            'exporter' => ['name' => 'nte-history-exporter', 'version' => '0.5.0'],
            'banner' => ['id' => $banner, 'name' => $banner],
            'scan' => ['decoded_records' => 1, 'exported_records' => 1, 'skipped_records' => 0, 'warnings' => [], 'pages_seen' => [1]],
            'user_uid' => $uid,
            'records' => [$record],
        ], JSON_THROW_ON_ERROR);
    }

    private function upload(string $content, string $name = 'export.json'): UploadedFile
    {
        $source = $this->projectDir . '/src_' . bin2hex(random_bytes(4)) . '_' . $name;
        file_put_contents($source, $content);

        return new UploadedFile($source, $name, 'application/json', null, true);
    }

    /**
     * @param list<UploadedFile> $files
     */
    private function import(array $files, ?PerfectworldAccount $requestAccount = null): Response
    {
        [$user, $account, $profile] = self::createOwner();
        $importer = new NteHistoryExportImporter(new NevernessToEvernessService(new NullLogger()), new NteCatalog(new ArrayAdapter()));
        $manager = new PullBackupImporterManager([$importer]);
        $importService = new PullBackupImportService(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(PerfectworldPullRepository::class),
            $manager,
            new LockFactory(new InMemoryStore()),
            $this->createStub(ManualPullService::class),
            new ServiceLocator([]),
        );

        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $message): Envelope {
            $this->dispatched[] = $message;

            return new Envelope($message);
        });

        $request = new Request([], ['importer' => NteHistoryExportImporter::getKey()], [], [], ['files' => $files]);

        return $this->createController()->apiGameProfilePullsImportBackup($request, $user, $requestAccount ?? $account, $profile, $manager, $importService, $this->tracker, $bus);
    }

    /**
     * @return list<string>
     */
    private function storedUploads(): array
    {
        return glob($this->projectDir . '/var/tmp/perfectworld_pull_imports/*') ?: [];
    }

    public function testValidFilesAreQueuedWithPerBannerProgress(): void
    {
        $response = $this->import([
            $this->upload($this->export(NevernessToEvernessService::BANNER_LIMITED), 'Limited.json'),
            $this->upload($this->export(NevernessToEvernessService::BANNER_ARC), 'Arc.json'),
        ]);

        $this->assertSame(Response::HTTP_ACCEPTED, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('backup_import', $payload['operation']['kind']);
        $this->assertSame(2, $payload['operation']['total']);
        $this->assertSame(['Lottery_LimitedCharacter', 'Arc_MiracleBox'], array_keys($payload['operation']['banners']));
        $this->assertSame('queued', $payload['operation']['banners']['Arc_MiracleBox']['status']);

        $this->assertCount(1, $this->dispatched);
        $message = $this->dispatched[0];
        $this->assertInstanceOf(ImportPullsBackupMessage::class, $message);
        $this->assertSame(5, $message->getGameProfileId());
        $this->assertCount(2, $message->getFilePaths());
        foreach ($message->getFilePaths() as $path) {
            $this->assertFileExists($path);
            $this->assertMatchesRegularExpression('/\/[0-9a-f-]{36}\.json$/', $path, 'Server-generated file names only');
        }
    }

    public function testExportOfAnotherCharacterIsRejectedAndCleanedUp(): void
    {
        try {
            $this->import([
                $this->upload($this->export(NevernessToEvernessService::BANNER_LIMITED)),
                $this->upload($this->export(NevernessToEvernessService::BANNER_ARC, '999999999999'), 'Other.json'),
            ]);
            $this->fail('Expected a BadRequestHttpException.');
        } catch (BadRequestHttpException $e) {
            $this->assertStringContainsString('Other.json: this export belongs to another character', $e->getMessage());
        }

        $this->assertSame([], $this->storedUploads());
        $this->assertSame([], $this->dispatched);
    }

    public function testTwoFilesForTheSameBannerAreRejected(): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('one file per banner');

        try {
            $this->import([
                $this->upload($this->export(NevernessToEvernessService::BANNER_STANDARD)),
                $this->upload($this->export(NevernessToEvernessService::BANNER_STANDARD)),
            ]);
        } finally {
            $this->assertSame([], $this->storedUploads());
        }
    }

    public function testInvalidFileContentIsRejectedWithItsName(): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('notes.json: This is not an nte-exporter file');

        $this->import([$this->upload('{"format":"other"}', 'notes.json')]);
    }

    public function testTooManyFilesAreRejected(): void
    {
        $files = [];
        foreach ([NevernessToEvernessService::BANNER_LIMITED, NevernessToEvernessService::BANNER_STANDARD, NevernessToEvernessService::BANNER_ARC, NevernessToEvernessService::BANNER_ARC] as $banner) {
            $files[] = $this->upload($this->export($banner));
        }

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('between 1 and 3 files');
        $this->import($files);
    }

    public function testUnsupportedExtensionIsRejected(): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Unsupported file extension ".csv"');
        $this->import([$this->upload('a,b', 'export.csv')]);
    }

    public function testActiveImportReturnsConflict(): void
    {
        $this->tracker->startBackupImport(5, 1, NteHistoryExportImporter::getKey(), 'nte-exporter', 1);

        $this->expectException(ConflictHttpException::class);
        $this->import([$this->upload($this->export(NevernessToEvernessService::BANNER_LIMITED))]);
    }

    public function testProfileOfAnotherAccountIsRejected(): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('does not belong to this account');
        $this->import([$this->upload($this->export(NevernessToEvernessService::BANNER_LIMITED))], new PerfectworldAccount());
    }
}
