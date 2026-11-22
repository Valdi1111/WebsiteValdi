<?php

namespace App\Tests\GryphlineBundle\MessageHandler;

use App\CoreBundle\Service\Notification\UnifiedNotificationService;
use App\GamesBundle\Model\Pull\Manual\FillerPlan;
use App\GamesBundle\Model\Pull\PullOperationKind;
use App\GryphlineBundle\Entity\GryphlineAccount;
use App\GryphlineBundle\Entity\GryphlineGameProfile;
use App\GryphlineBundle\Entity\GryphlinePull;
use App\GryphlineBundle\Message\Profile\SyncPullsPageProfileMessage;
use App\GryphlineBundle\MessageHandler\Profile\SyncPullsPageProfileHandler;
use App\GryphlineBundle\Repository\GryphlineGameProfileRepository;
use App\GryphlineBundle\Repository\GryphlinePullRepository;
use App\GryphlineBundle\Service\EndfieldService;
use App\GryphlineBundle\Service\Pull\ManualPullService;
use App\GryphlineBundle\Service\Pull\PullOperationTracker;
use App\GryphlineBundle\Service\Pull\PullSyntheticReconcilerManager;
use App\GryphlineBundle\Service\SkportAuthService;
use App\GryphlineBundle\Service\SkportSignerService;
use App\GryphlineBundle\Service\SkportUtilsService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * A page that throws is retried by Messenger: whatever step failed, the retry must not stop the pagination early.
 */
class SyncPullsPageRetryTest extends TestCase
{
    private const string BANNER = 'E_CharacterGachaPoolType_Special';

    /**
     * Upstream history of the banner: cursor => [seq ids of the page, next cursor].
     */
    private const array PAGES = [
        '' => [['7003', '7002'], '7002'],
        '7002' => [['7001', '7000'], null],
    ];

    /**
     * @return iterable<string, array{string}>
     */
    public static function failingStepProvider(): iterable
    {
        yield 'next page dispatch' => ['dispatch'];
        yield 'flush' => ['flush'];
        yield 'tracker write after the commit' => ['tracker'];
    }

    #[DataProvider('failingStepProvider')]
    public function testARetriedPageStillSyncsTheWholeBanner(string $failingStep): void
    {
        $state = new class() {
            /** @var list<string> Seq ids committed to the database */
            public array $stored = [];
            /** @var list<string> Seq ids persisted but not flushed yet */
            public array $pending = [];
            /** @var list<SyncPullsPageProfileMessage> */
            public array $queue = [];
            public ?string $failingStep = null;
        };
        $state->failingStep = $failingStep;

        $cache = new class() extends ArrayAdapter {
            public bool $failNextSave = false;

            public function save(CacheItemInterface $item): bool
            {
                if ($this->failNextSave) {
                    $this->failNextSave = false;
                    throw new \RuntimeException('Cache unavailable.');
                }

                return parent::save($item);
            }
        };
        $tracker = new PullOperationTracker($cache, $this->createStub(HubInterface::class), 'games.test');
        $operationId = (string) $tracker->startSyncOperation(10, 1, PullOperationKind::AUTO_SYNC, [self::BANNER => 'Chartered Headhunting'])['id'];

        $handler = $this->createHandler($state, $cache, $tracker);

        // Emulates the Messenger worker: a failed message is retried once the entity manager has been cleared
        $state->queue[] = new SyncPullsPageProfileMessage(10, self::BANNER, null, 'u8-token-test', $operationId);
        $handled = 0;
        while ($state->queue !== []) {
            $this->assertLessThan(10, ++$handled, 'The pagination does not terminate.');
            $message = array_shift($state->queue);
            try {
                $handler($message);
            } catch (\RuntimeException) {
                $state->pending = [];
                $state->queue[] = $message;
            }
        }

        $this->assertNull($state->failingStep, 'The failure was never injected.');
        $stored = $state->stored;
        sort($stored);
        $this->assertSame(['7000', '7001', '7002', '7003'], $stored);

        $operation = $tracker->getOperation($operationId);
        $this->assertSame('completed', $operation['status']);
        $this->assertSame('completed', $operation['banners'][self::BANNER]['status']);
    }

    private function createHandler(object $state, ArrayAdapter $cache, PullOperationTracker $tracker): SyncPullsPageProfileHandler
    {
        $account = (new GryphlineAccount())->setAccountToken('acc-tok');
        $profile = (new GryphlineGameProfile())
            ->setAccount($account)
            ->setGameId(EndfieldService::GAME_ID)
            ->setGameBiz(EndfieldService::GAME_BIZ)
            ->setGameUid('90001')
            ->setNickname('Endministrator');
        (new \ReflectionProperty(GryphlineGameProfile::class, 'id'))->setValue($profile, 10);

        $endfield = self::getStubBuilder(EndfieldService::class)
            ->setConstructorArgs([
                $this->createStub(HttpClientInterface::class),
                $this->createStub(HttpClientInterface::class),
                $this->createStub(SkportSignerService::class),
                $this->createStub(SkportAuthService::class),
                $this->createStub(SkportUtilsService::class),
                $this->createStub(DenormalizerInterface::class),
                $this->createStub(ObjectMapperInterface::class),
                new NullLogger(),
            ])
            ->onlyMethods(['fetchPullsPage'])
            ->getStub();
        $endfield->method('fetchPullsPage')->willReturnCallback(static function (
            GryphlineGameProfile $profile,
            string $category,
            string $u8Token,
            ?string $seqId,
            ?string &$nextSeqId
        ): array {
            [$seqIds, $nextSeqId] = self::PAGES[$seqId ?? ''];

            return array_map(static fn (string $id): GryphlinePull => (new GryphlinePull())
                ->setSeqId($id)
                ->setPoolCategory('char')
                ->setPoolType(self::BANNER)
                ->setName('Pull ' . $id)
                ->setPulledAt(new \DateTimeImmutable()), $seqIds);
        });

        $profileRepo = $this->createStub(GryphlineGameProfileRepository::class);
        $profileRepo->method('find')->willReturn($profile);

        // A pull exists only once its page has been committed
        $pullRepo = $this->createStub(GryphlinePullRepository::class);
        $pullRepo->method('existsByProfileAndSeqId')->willReturnCallback(
            static fn (GryphlineGameProfile $p, string $seqId): bool => in_array($seqId, $state->stored, true)
        );

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (GryphlinePull $pull) use ($state): void {
            $state->pending[] = $pull->getSeqId();
        });
        $em->method('flush')->willReturnCallback(static function () use ($state, $cache): void {
            if ($state->failingStep === 'flush') {
                $state->failingStep = null;
                throw new \RuntimeException('Deadlock found when trying to get lock.');
            }
            array_push($state->stored, ...$state->pending);
            $state->pending = [];
            if ($state->failingStep === 'tracker') {
                // The page is committed: the next tracker write fails
                $state->failingStep = null;
                $cache->failNextSave = true;
            }
        });

        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static function (object $message, array $stamps) use ($state): Envelope {
            if ($state->failingStep === 'dispatch') {
                $state->failingStep = null;
                throw new \RuntimeException('Transport unavailable.');
            }
            $state->queue[] = $message;

            return new Envelope($message, $stamps);
        });

        $manualPullService = $this->createStub(ManualPullService::class);
        $manualPullService->method('realign')->willReturn(new FillerPlan());

        return new SyncPullsPageProfileHandler(
            $profileRepo,
            new ServiceLocator([EndfieldService::GAME_BIZ => static fn () => $endfield]),
            new NullLogger(),
            $this->createStub(UnifiedNotificationService::class),
            $this->createStub(SkportAuthService::class),
            $pullRepo,
            $em,
            $bus,
            new PullSyntheticReconcilerManager([]),
            $tracker,
            new LockFactory(new InMemoryStore()),
            $manualPullService,
        );
    }
}
