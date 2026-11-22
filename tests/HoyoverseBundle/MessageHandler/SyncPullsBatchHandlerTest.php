<?php

namespace App\Tests\HoyoverseBundle\MessageHandler;

use App\HoyoverseBundle\Entity\HoyoverseAccount;
use App\HoyoverseBundle\Entity\HoyoverseGameProfile;
use App\HoyoverseBundle\Message\Batch\SyncPullsBatchMessage;
use App\HoyoverseBundle\Message\Profile\SyncPullsPageProfileMessage;
use App\HoyoverseBundle\MessageHandler\Batch\SyncPullsBatchHandler;
use App\HoyoverseBundle\Repository\HoyoverseGameProfileRepository;
use App\HoyoverseBundle\Service\GenshinImpactService;
use App\HoyoverseBundle\Service\HonkaiStarRailService;
use App\HoyoverseBundle\Service\HoyolabCookieUtilsService;
use App\HoyoverseBundle\Service\HoyolabUtilsService;
use App\HoyoverseBundle\Service\Pull\PullOperationTracker;
use App\HoyoverseBundle\Service\Pull\PullSyncSourceResolver;
use App\HoyoverseBundle\Service\ZenlessZoneZeroService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\NameConverter\MetadataAwareNameConverter;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

/**
 * Nightly sync: only profiles that can sync with their account cookie get banner streams.
 */
class SyncPullsBatchHandlerTest extends TestCase
{
    private const string HOYOLAB_COOKIE = 'ltoken_v2=v2_lt; ltuid_v2=111; ltmid_v2=mid111';
    private const string STOKEN_COOKIE = self::HOYOLAB_COOKIE . '; stoken_v2=v2_st';

    public function testDispatchesBannerStreamsOnlyForProfilesThatCanSync(): void
    {
        $profiles = [
            1 => [ZenlessZoneZeroService::GAME_ID, self::HOYOLAB_COOKIE],
            2 => [GenshinImpactService::GAME_ID, self::HOYOLAB_COOKIE],
            3 => [GenshinImpactService::GAME_ID, self::STOKEN_COOKIE],
            4 => [HonkaiStarRailService::GAME_ID, self::STOKEN_COOKIE],
        ];
        $entities = [];
        foreach ($profiles as $id => [$gameId, $cookie]) {
            $profile = (new HoyoverseGameProfile())
                ->setAccount((new HoyoverseAccount())->setCookie($cookie))
                ->setGameId($gameId);
            (new \ReflectionProperty(HoyoverseGameProfile::class, 'id'))->setValue($profile, $id);
            $entities[$id] = $profile;
        }

        $repo = $this->createMock(HoyoverseGameProfileRepository::class);
        $repo->expects($this->once())->method('findEligibleProfileIds')->with('syncPulls', null, null)->willReturn(
            array_map(static fn (int $id): array => ['id' => $id, 'gameId' => $profiles[$id][0]], array_keys($profiles))
        );
        $repo->method('find')->willReturnCallback(static fn (int $id): ?HoyoverseGameProfile => $entities[$id] ?? null);

        $dispatched = [];
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->exactly(11))->method('dispatch')->willReturnCallback(static function (object $envelope) use (&$dispatched): Envelope {
            $dispatched[] = $envelope;

            return $envelope instanceof Envelope ? $envelope : new Envelope($envelope);
        });

        $metadataFactory = new ClassMetadataFactory(new AttributeLoader());
        $serializer = new Serializer([new ObjectNormalizer($metadataFactory, new MetadataAwareNameConverter($metadataFactory))]);
        $cookieUtils = new HoyolabCookieUtilsService(new MockHttpClient(), new NullLogger(), $serializer, $this->createStub(HoyolabUtilsService::class));
        $tracker = new PullOperationTracker(new ArrayAdapter(), $this->createStub(HubInterface::class), 'games.test');

        $handler = new SyncPullsBatchHandler($repo, $bus, new NullLogger());
        $handler->setLocatorByGameId(new ServiceLocator([
            GenshinImpactService::GAME_ID => static fn () => new GenshinImpactService(new NullLogger()),
            HonkaiStarRailService::GAME_ID => static fn () => new HonkaiStarRailService(new NullLogger()),
            ZenlessZoneZeroService::GAME_ID => static fn () => new ZenlessZoneZeroService(new NullLogger()),
        ]));
        $handler->setOperationTracker($tracker);
        $handler->setPullSyncSourceResolver(new PullSyncSourceResolver($cookieUtils));

        $handler(new SyncPullsBatchMessage());

        $streams = [];
        foreach ($dispatched as $envelope) {
            $this->assertInstanceOf(Envelope::class, $envelope);
            $message = $envelope->getMessage();
            $this->assertInstanceOf(SyncPullsPageProfileMessage::class, $message);
            $this->assertNull($message->getAuthkey());
            $this->assertSame('0', $message->getEndId());
            $delay = $envelope->last(DelayStamp::class)?->getDelay() ?? 0;
            $streams[$message->getGameProfileId()][] = [$message->getGachaType(), $delay];
        }

        // Zenless Zone Zero without stoken_v2 syncs from the battle chronicle; Genshin needs stoken_v2; Star Rail never
        $this->assertSame([1, 3], array_keys($streams));
        $this->assertSame([['2', 0], ['3', 30000], ['12', 60000], ['13', 90000], ['1', 120000], ['5', 150000]], $streams[1]);
        $this->assertSame([['301', 0], ['302', 30000], ['200', 60000], ['100', 90000], ['500', 120000]], $streams[3]);
        $this->assertSame('scheduled_sync', $tracker->getActiveOperationForProfile(1)['kind']);
        $this->assertNull($tracker->getActiveOperationForProfile(2));
        $this->assertNull($tracker->getActiveOperationForProfile(4));
    }
}
