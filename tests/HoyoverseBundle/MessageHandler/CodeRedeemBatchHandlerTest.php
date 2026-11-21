<?php

namespace App\Tests\HoyoverseBundle\MessageHandler;

use App\CoreBundle\Entity\User;
use App\CoreBundle\Model\Notification\UniversalEmbed;
use App\CoreBundle\Service\Notification\UnifiedNotificationService;
use App\HoyoverseBundle\Entity\HoyoverseAccount;
use App\HoyoverseBundle\Entity\HoyoverseGameProfile;
use App\HoyoverseBundle\Message\Batch\CodeRedeemBatchMessage;
use App\HoyoverseBundle\Message\Profile\CodeRedeemProfileMessage;
use App\HoyoverseBundle\MessageHandler\Batch\CodeRedeemBatchHandler;
use App\HoyoverseBundle\Model\RedeemableCode;
use App\HoyoverseBundle\Repository\HoyoverseGameProfileRepository;
use App\HoyoverseBundle\Service\GenshinImpactService;
use App\HoyoverseBundle\Service\HoyolabCookieUtilsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Auto-redeemable games: profiles whose account cookie lacks the redemption tokens get a manual-redemption
 * notification (once per code) instead of an API attempt that can only fail.
 */
class CodeRedeemBatchHandlerTest extends TestCase
{
    private const string FULL_COOKIE = 'full';
    private const string LTOKEN_ONLY_COOKIE = 'ltoken-only';

    /** @var list<object> */
    private array $dispatched = [];
    /** @var list<UniversalEmbed> */
    private array $notifications = [];
    private ArrayAdapter $cache;

    protected function setUp(): void
    {
        $this->cache = new ArrayAdapter();
    }

    private static function profile(int $id, string $cookie): HoyoverseGameProfile
    {
        $user = new User();
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, 1);
        $account = (new HoyoverseAccount())->setUser($user)->setCookie($cookie);
        $profile = (new HoyoverseGameProfile())
            ->setAccount($account)
            ->setGameId(GenshinImpactService::GAME_ID)
            ->setGameName('Genshin Impact')
            ->setGameUid('700000001')
            ->setNickname('Traveler')
            ->setLevel(60)
            ->setParsedRegion('Europe');
        (new \ReflectionProperty(HoyoverseGameProfile::class, 'id'))->setValue($profile, $id);

        return $profile;
    }

    /**
     * @param list<HoyoverseGameProfile> $profiles
     */
    private function createHandler(array $profiles): CodeRedeemBatchHandler
    {
        // Real game service (static getGameId() cannot be stubbed), with the code source replaced
        $genshin = new class(new NullLogger()) extends GenshinImpactService {
            public function fetchRedeemableCodes(): array
            {
                return [(new RedeemableCode())->setCode('GENSHINGIFT')->setRewards(['Primogem x60'])];
            }
        };

        $repository = $this->createStub(HoyoverseGameProfileRepository::class);
        $repository->method('findEligibleProfiles')->willReturn($profiles);

        $notificationService = $this->createStub(UnifiedNotificationService::class);
        $notificationService->method('sendToUser')->willReturnCallback(function (User $user, UniversalEmbed $embed): void {
            $this->notifications[] = $embed;
        });

        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $message): Envelope {
            $this->dispatched[] = $message;

            return new Envelope($message);
        });

        // The cookie parsing itself is covered by HoyolabCookieUtilsServiceTest
        $cookieUtils = $this->createStub(HoyolabCookieUtilsService::class);
        $cookieUtils->method('canRedeemCodes')->willReturnCallback(static fn (?string $cookie): bool => $cookie === self::FULL_COOKIE);

        return new CodeRedeemBatchHandler(
            $repository,
            new ServiceLocator([GenshinImpactService::class => static fn () => $genshin]),
            new NullLogger(),
            $notificationService,
            $this->cache,
            $bus,
            $cookieUtils,
        );
    }

    public function testProfileWithRedemptionTokensIsQueuedForAutomaticRedemption(): void
    {
        ($this->createHandler([self::profile(10, self::FULL_COOKIE)]))(new CodeRedeemBatchMessage());

        $this->assertCount(1, $this->dispatched);
        $this->assertInstanceOf(CodeRedeemProfileMessage::class, $this->dispatched[0]);
        $this->assertSame([], $this->notifications);
    }

    public function testProfileWithoutRedemptionTokensIsToldToRedeemManuallyOnce(): void
    {
        $handler = $this->createHandler([self::profile(11, self::LTOKEN_ONLY_COOKIE)]);

        $handler(new CodeRedeemBatchMessage());
        $handler(new CodeRedeemBatchMessage());

        $this->assertSame([], $this->dispatched, 'No API attempt without the tokens');
        $this->assertCount(1, $this->notifications, 'One notification per code, not one every 15 minutes');
        $this->assertStringContainsString('Manual Redemption Required', (string) $this->notifications[0]->getDescription());
        $this->assertStringContainsString('Automatic redemption is not configured', (string) $this->notifications[0]->getDescription());
        $fields = implode(' ', array_map(static fn ($field): string => (string) $field->getValue(), $this->notifications[0]->getFields()));
        $this->assertStringContainsString('?code=GENSHINGIFT', $fields, 'Redemption link with the code');
        $this->assertStringContainsString('Primogem x60', $fields);
    }

    public function testCodeAlreadyRedeemedBeforeTheTokensWentMissingIsNotNotified(): void
    {
        $this->cache->save($this->cache->getItem('redeemed_13_GENSHINGIFT')->set(true));

        ($this->createHandler([self::profile(13, self::LTOKEN_ONLY_COOKIE)]))(new CodeRedeemBatchMessage());

        $this->assertSame([], $this->notifications);
    }
}
