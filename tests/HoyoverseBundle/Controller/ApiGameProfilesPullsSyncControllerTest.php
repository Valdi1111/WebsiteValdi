<?php

namespace App\Tests\HoyoverseBundle\Controller;

use App\CoreBundle\Entity\User;
use App\HoyoverseBundle\Controller\ApiGameProfilesController;
use App\HoyoverseBundle\Entity\HoyoverseAccount;
use App\HoyoverseBundle\Entity\HoyoverseGameProfile;
use App\HoyoverseBundle\Message\Profile\SyncPullsPageProfileMessage;
use App\HoyoverseBundle\Service\GenshinImpactService;
use App\HoyoverseBundle\Service\HonkaiStarRailService;
use App\HoyoverseBundle\Service\HoyolabCookieUtilsService;
use App\HoyoverseBundle\Service\HoyolabUtilsService;
use App\HoyoverseBundle\Service\Pull\PullOperationTracker;
use App\HoyoverseBundle\Service\Pull\PullSyncSourceResolver;
use App\HoyoverseBundle\Service\ZenlessZoneZeroService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\NameConverter\MetadataAwareNameConverter;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * POST .../pulls/sync: allowed from the game and the account cookie (stoken_v2, or HoYoLAB cookies for Zenless Zone Zero).
 */
class ApiGameProfilesPullsSyncControllerTest extends TestCase
{
    private const string HOYOLAB_COOKIE = 'ltoken_v2=v2_lt; ltuid_v2=111; ltmid_v2=mid111';
    private const string STOKEN_COOKIE = self::HOYOLAB_COOKIE . '; stoken_v2=v2_st';

    private function createController(): ApiGameProfilesController
    {
        $locator = new ServiceLocator([
            GenshinImpactService::GAME_ID => static fn () => new GenshinImpactService(new NullLogger()),
            HonkaiStarRailService::GAME_ID => static fn () => new HonkaiStarRailService(new NullLogger()),
            ZenlessZoneZeroService::GAME_ID => static fn () => new ZenlessZoneZeroService(new NullLogger()),
        ]);

        $controller = new ApiGameProfilesController($this->createStub(EntityManagerInterface::class), $locator, sys_get_temp_dir());

        $serializer = $this->createStub(SerializerInterface::class);
        $serializer->method('serialize')->willReturnCallback(
            static fn (mixed $data): string => json_encode($data, JSON_THROW_ON_ERROR)
        );
        $container = new Container();
        $container->set('serializer', $serializer);
        $controller->setContainer($container);

        return $controller;
    }

    private function createResolver(): PullSyncSourceResolver
    {
        $metadataFactory = new ClassMetadataFactory(new AttributeLoader());
        $serializer = new Serializer([new ObjectNormalizer($metadataFactory, new MetadataAwareNameConverter($metadataFactory))]);

        return new PullSyncSourceResolver(
            new HoyolabCookieUtilsService(new MockHttpClient(), new NullLogger(), $serializer, $this->createStub(HoyolabUtilsService::class))
        );
    }

    /**
     * @return array{0: User, 1: HoyoverseAccount, 2: HoyoverseGameProfile}
     */
    private function createAccountAndProfile(int $gameId, string $cookie): array
    {
        $user = new User();
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, 1);
        $account = (new HoyoverseAccount())->setUser($user)->setCookie($cookie);
        $profile = (new HoyoverseGameProfile())->setAccount($account)->setGameId($gameId)->setNickname('Proxy');
        (new \ReflectionProperty(HoyoverseGameProfile::class, 'id'))->setValue($profile, 30);

        return [$user, $account, $profile];
    }

    /**
     * @return iterable<string, array{int, string, list<string>}>
     */
    public static function allowedProvider(): iterable
    {
        yield 'Zenless Zone Zero with HoYoLAB cookies only' => [ZenlessZoneZeroService::GAME_ID, self::HOYOLAB_COOKIE, ['2', '3', '12', '13', '1', '5']];
        yield 'Zenless Zone Zero with stoken_v2' => [ZenlessZoneZeroService::GAME_ID, self::STOKEN_COOKIE, ['2', '3', '12', '13', '1', '5']];
        yield 'Genshin Impact with stoken_v2' => [GenshinImpactService::GAME_ID, self::STOKEN_COOKIE, ['301', '302', '200', '100', '500']];
    }

    /**
     * @param list<string> $banners
     */
    #[DataProvider('allowedProvider')]
    public function testQueuesOneStreamPerBannerWithoutAuthkey(int $gameId, string $cookie, array $banners): void
    {
        [$user, $account, $profile] = $this->createAccountAndProfile($gameId, $cookie);
        $tracker = new PullOperationTracker(new ArrayAdapter(), $this->createStub(HubInterface::class), 'games.test');

        $dispatched = [];
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->exactly(count($banners)))->method('dispatch')
            ->willReturnCallback(static function (object $message, array $stamps) use (&$dispatched): Envelope {
                $dispatched[] = [$message, $stamps];

                return new Envelope($message, $stamps);
            });

        $response = $this->createController()->apiGameProfilePullsSync($user, $account, $profile, $bus, $tracker, $this->createResolver());

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($payload['success']);
        $this->assertSame('stoken_sync', $payload['operation']['kind']);
        $this->assertSame($banners, array_map('strval', array_keys($payload['operation']['banners'])));

        foreach ($dispatched as $index => [$message, $stamps]) {
            $this->assertInstanceOf(SyncPullsPageProfileMessage::class, $message);
            $this->assertSame(30, $message->getGameProfileId());
            $this->assertSame($banners[$index], $message->getGachaType());
            $this->assertSame('0', $message->getEndId());
            // The page handler picks the source (authkey from stoken_v2 or battle chronicle) from the cookie
            $this->assertNull($message->getAuthkey());
            $this->assertSame($payload['operation']['id'], $message->getOperationId());
            if ($index === 0) {
                $this->assertSame([], $stamps);
            } else {
                $this->assertInstanceOf(DelayStamp::class, $stamps[0]);
                $this->assertSame($index * 15000, $stamps[0]->getDelay());
            }
        }
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function refusedProvider(): iterable
    {
        yield 'Genshin Impact without stoken_v2' => [GenshinImpactService::GAME_ID, self::HOYOLAB_COOKIE];
        yield 'Honkai: Star Rail with stoken_v2' => [HonkaiStarRailService::GAME_ID, self::STOKEN_COOKIE];
        yield 'Zenless Zone Zero with an incomplete cookie' => [ZenlessZoneZeroService::GAME_ID, 'ltuid_v2=111; ltmid_v2=mid111'];
    }

    #[DataProvider('refusedProvider')]
    public function testRefusesProfilesThatCannotSync(int $gameId, string $cookie): void
    {
        [$user, $account, $profile] = $this->createAccountAndProfile($gameId, $cookie);
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch');

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('This game profile does not support automated pull synchronization with the current account cookie.');

        $this->createController()->apiGameProfilePullsSync(
            $user,
            $account,
            $profile,
            $bus,
            new PullOperationTracker(new ArrayAdapter(), $this->createStub(HubInterface::class), 'games.test'),
            $this->createResolver(),
        );
    }
}
