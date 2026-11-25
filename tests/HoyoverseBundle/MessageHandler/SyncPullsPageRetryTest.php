<?php

namespace App\Tests\HoyoverseBundle\MessageHandler;

use App\CoreBundle\Service\Notification\UnifiedNotificationService;
use App\GamesBundle\Model\Pull\Manual\FillerPlan;
use App\GamesBundle\Model\Pull\PullOperationKind;
use App\HoyoverseBundle\Entity\HoyoverseAccount;
use App\HoyoverseBundle\Entity\HoyoverseGameProfile;
use App\HoyoverseBundle\Entity\HoyoversePull;
use App\HoyoverseBundle\Message\Profile\SyncPullsPageProfileMessage;
use App\HoyoverseBundle\MessageHandler\Profile\SyncPullsPageProfileHandler;
use App\HoyoverseBundle\Repository\HoyoverseGameProfileRepository;
use App\HoyoverseBundle\Repository\HoyoversePullRepository;
use App\HoyoverseBundle\Service\GenshinImpactService;
use App\HoyoverseBundle\Service\Pull\ManualPullService;
use App\HoyoverseBundle\Service\Pull\PullOperationTracker;
use App\HoyoverseBundle\Service\Pull\PullSyntheticReconcilerManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Tests\HoyoverseBundle\CookieUtilsStubTrait;
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

/**
 * A page that throws is retried by Messenger: whatever step failed, the retry must not stop the pagination early.
 */
class SyncPullsPageRetryTest extends TestCase
{
    use CookieUtilsStubTrait;

    private const string BANNER = '301';

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
            /** @var list<string> Pull ids committed to the database */
            public array $stored = [];
            /** @var list<string> Pull ids persisted but not flushed yet */
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
        $operationId = (string) $tracker->startSyncOperation(10, 1, PullOperationKind::STOKEN_SYNC, [self::BANNER => 'Character Event Wish'])['id'];

        $handler = $this->createHandler($state, $cache, $tracker);

        // Emulates the Messenger worker: a failed message is retried once the entity manager has been cleared
        $state->queue[] = new SyncPullsPageProfileMessage(10, self::BANNER, '0', 'authkey-test', [], $operationId);
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
        // A full page (20 pulls) followed by a last, shorter one (5 pulls)
        $this->assertSame(array_map('strval', range(1016, 1040)), $stored);

        $operation = $tracker->getOperation($operationId);
        $this->assertSame('completed', $operation['status']);
        $this->assertSame('completed', $operation['banners'][self::BANNER]['status']);
    }

    private function createHandler(object $state, ArrayAdapter $cache, PullOperationTracker $tracker): SyncPullsPageProfileHandler
    {
        $profile = (new HoyoverseGameProfile())
            ->setAccount((new HoyoverseAccount())->setCookie('ltoken_v2=abc; ltuid_v2=123'))
            ->setGameId(GenshinImpactService::GAME_ID)
            ->setGameBiz(GenshinImpactService::GAME_BIZ)
            ->setNickname('Traveler');
        (new \ReflectionProperty(HoyoverseGameProfile::class, 'id'))->setValue($profile, 10);

        // Upstream history of the banner, newest first: a page holds the pulls older than its end id
        $giService = self::getStubBuilder(GenshinImpactService::class)
            ->setConstructorArgs([new NullLogger()])
            ->onlyMethods(['fetchPullsPage'])
            ->getStub();
        $giService->method('fetchPullsPage')->willReturnCallback(static function (
            HoyoverseGameProfile $profile,
            string $gachaType,
            string $authkey,
            string $endId,
            array $extraQueryParams,
            int $pageSize
        ): array {
            $ids = array_values(array_filter(range(1040, 1016), static fn (int $id): bool => $endId === '0' || $id < (int) $endId));

            return array_map(static fn (int $id): HoyoversePull => (new HoyoversePull())
                ->setPullId((string) $id)
                ->setGachaType($gachaType)
                ->setName('Pull ' . $id)
                ->setPulledAt(new \DateTimeImmutable()), array_slice($ids, 0, $pageSize));
        });

        $profileRepo = $this->createStub(HoyoverseGameProfileRepository::class);
        $profileRepo->method('find')->willReturn($profile);

        // A pull exists only once its page has been committed
        $pullRepo = $this->createStub(HoyoversePullRepository::class);
        $pullRepo->method('existsByProfileAndPullId')->willReturnCallback(
            static fn (HoyoverseGameProfile $p, string $pullId): bool => in_array($pullId, $state->stored, true)
        );

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (HoyoversePull $pull) use ($state): void {
            $state->pending[] = $pull->getPullId();
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

        $handler = new SyncPullsPageProfileHandler(
            $profileRepo,
            $this->parsingCookieUtils(),
            new ServiceLocator([GenshinImpactService::GAME_ID => static fn () => $giService]),
            new NullLogger(),
            $this->createStub(UnifiedNotificationService::class),
        );
        $handler->setEntityManager($em);
        $handler->setPullRepository($pullRepo);
        $handler->setBus($bus);
        $handler->setSyntheticReconcilerManager(new PullSyntheticReconcilerManager([]));
        $handler->setOperationTracker($tracker);
        $handler->setLockFactory(new LockFactory(new InMemoryStore()));
        $handler->setManualPullService($manualPullService);

        return $handler;
    }
}
