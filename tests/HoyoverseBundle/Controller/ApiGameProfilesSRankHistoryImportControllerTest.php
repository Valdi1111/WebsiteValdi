<?php

namespace App\Tests\HoyoverseBundle\Controller;

use App\CoreBundle\Entity\User;
use App\HoyoverseBundle\Controller\ApiGameProfilesController;
use App\HoyoverseBundle\Entity\HoyoverseAccount;
use App\HoyoverseBundle\Entity\HoyoverseGameProfile;
use App\HoyoverseBundle\Message\Profile\ImportSRankHistoryMessage;
use App\HoyoverseBundle\Service\GenshinImpactService;
use App\HoyoverseBundle\Service\HoyolabCookieUtilsService;
use App\HoyoverseBundle\Service\HoyolabUtilsService;
use App\HoyoverseBundle\Service\Pull\PullOperationTracker;
use App\HoyoverseBundle\Service\ZenlessZoneZeroService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\NameConverter\MetadataAwareNameConverter;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * POST .../pulls/import-s-rank-history: checks, operation and message.
 */
class ApiGameProfilesSRankHistoryImportControllerTest extends TestCase
{
    private User $user;
    private HoyoverseAccount $account;
    private PullOperationTracker $tracker;

    /** @var list<object> */
    private array $dispatched = [];

    protected function setUp(): void
    {
        $this->user = new User();
        (new \ReflectionProperty(User::class, 'id'))->setValue($this->user, 1);
        $this->account = (new HoyoverseAccount())->setUser($this->user)->setCookie('ltoken_v2=v2_test; ltuid_v2=100000001; ltmid_v2=mid_test');
        $this->tracker = new PullOperationTracker(new ArrayAdapter(), $this->createStub(HubInterface::class), 'games.test');
    }

    private function createProfile(int $gameId): HoyoverseGameProfile
    {
        $profile = (new HoyoverseGameProfile())->setAccount($this->account)->setGameId($gameId)->setNickname('Proxy');
        (new \ReflectionProperty(HoyoverseGameProfile::class, 'id'))->setValue($profile, 10);

        return $profile;
    }

    private function import(HoyoverseGameProfile $profile): Response
    {
        $zzz = new ZenlessZoneZeroService(new NullLogger());
        $genshin = new GenshinImpactService(new NullLogger());
        $controller = new ApiGameProfilesController(
            $this->createStub(EntityManagerInterface::class),
            new ServiceLocator([
                ZenlessZoneZeroService::GAME_ID => static fn () => $zzz,
                GenshinImpactService::GAME_ID => static fn () => $genshin,
            ]),
            sys_get_temp_dir(),
        );
        $serializer = $this->createStub(SerializerInterface::class);
        $serializer->method('serialize')->willReturnCallback(static fn (mixed $data): string => json_encode($data, JSON_THROW_ON_ERROR));
        $container = new Container();
        $container->set('serializer', $serializer);
        $controller->setContainer($container);

        $metadataFactory = new ClassMetadataFactory(new AttributeLoader());
        $cookieUtils = new HoyolabCookieUtilsService(
            new MockHttpClient(),
            new NullLogger(),
            new Serializer([new ObjectNormalizer($metadataFactory, new MetadataAwareNameConverter($metadataFactory))]),
            $this->createStub(HoyolabUtilsService::class),
        );

        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $message): Envelope {
            $this->dispatched[] = $message;

            return new Envelope($message);
        });

        return $controller->apiGameProfilePullsImportSRankHistory($this->user, $this->account, $profile, $cookieUtils, $this->tracker, $bus);
    }

    public function testQueuesTheImportAsATrackedOperation(): void
    {
        $response = $this->import($this->createProfile(ZenlessZoneZeroService::GAME_ID));

        $this->assertSame(Response::HTTP_ACCEPTED, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($payload['success']);
        $this->assertSame('s_rank_history_import', $payload['operation']['kind']);
        $this->assertSame([2, 3, 12, 13, 1, 5], array_keys($payload['operation']['banners']), 'Every configured banner (numeric keys)');
        $this->assertCount(1, $this->dispatched);
        $this->assertInstanceOf(ImportSRankHistoryMessage::class, $this->dispatched[0]);
        $this->assertSame([$payload['operation']['id'], 10, 0], [$this->dispatched[0]->getOperationId(), $this->dispatched[0]->getGameProfileId(), $this->dispatched[0]->getLockAttempt()]);

        // A second click while it runs
        try {
            $this->import($this->createProfile(ZenlessZoneZeroService::GAME_ID));
            $this->fail('Expected a conflict');
        } catch (ConflictHttpException) {
        }
        $this->assertCount(1, $this->dispatched);
    }

    public function testRefusesAGameWithoutHistoryAndAnUnusableCookie(): void
    {
        try {
            $this->import($this->createProfile(GenshinImpactService::GAME_ID));
            $this->fail('Genshin Impact has no S-rank history');
        } catch (BadRequestHttpException $e) {
            $this->assertSame('This game profile cannot import an official S-rank history.', $e->getMessage());
        }

        $this->account->setCookie('ltoken_v2=v2_test');
        try {
            $this->import($this->createProfile(ZenlessZoneZeroService::GAME_ID));
            $this->fail('An incomplete cookie');
        } catch (BadRequestHttpException $e) {
            $this->assertStringNotContainsString('v2_test', $e->getMessage());
        }

        $this->assertSame([], $this->dispatched);
        $this->assertNull($this->tracker->getActiveOperationForProfile(10));
    }
}
