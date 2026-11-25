<?php

namespace App\Tests\GamesBundle\Controller;

use App\CoreBundle\Entity\User;
use App\CoreBundle\Service\UserRoleResolver;
use App\GamesBundle\Model\Profile\AccountHealth;
use App\GamesBundle\Presenter\ProfileSummaryPresenter;
use App\GamesBundle\Service\Profile\GameProfileRegistry;
use App\Tests\CoreBundle\Controller\WithoutDatabaseClientTrait;
use App\Tests\GamesBundle\Status\Fixtures\FakeGameProfile;
use App\Tests\GamesBundle\Status\Fixtures\FakeNotesGameService;
use App\Tests\GamesBundle\Status\Fixtures\FakeProfileProvider;
use App\Tests\GamesBundle\Status\Fixtures\OtherFakeGameProfile;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * GET /api/{provider}/gameProfiles/{id}/status through the whole HTTP stack (firewall, IsGranted, role hierarchy,
 * rate limiter), without a database. The registry holds two fake providers behind the real role resolver; the
 * collector of the container reads them with the real sections: stamina and dailies support the fake game.
 */
class ApiProfileStatusControllerTest extends WebTestCase
{
    use WithoutDatabaseClientTrait;

    private FakeProfileProvider $fake;
    private FakeProfileProvider $other;
    private FakeNotesGameService $service;
    private User $user;

    protected function setUp(): void
    {
        $this->roles['ROLE_USER_GAMES'] = self::role('ROLE_USER_GAMES', 'Games', 'Games');
        $this->roles['ROLE_USER_FAKE'] = self::role('ROLE_USER_FAKE', 'Fake games', 'Games');
        $this->roles['ROLE_USER_OTHER'] = self::role('ROLE_USER_OTHER', 'Other games', 'Games');

        $this->service = new FakeNotesGameService(new \DateTimeImmutable('@' . (time() - 300)));
        $this->fake = new FakeProfileProvider('fake', FakeGameProfile::class);
        $this->fake->services = ['fake_game' => $this->service];
        $this->other = new FakeProfileProvider('other', OtherFakeGameProfile::class);
        $this->other->services = ['fake_game' => $this->service];
    }

    /**
     * @param list<string> $roles roles of the logged-in user
     */
    private function createStatusClient(array $roles = ['ROLE_USER_GAMES', 'ROLE_USER_FAKE']): KernelBrowser
    {
        $client = $this->createClientWithoutDatabase();
        $container = static::getContainer();

        $registry = new GameProfileRegistry(
            [$this->fake, $this->other],
            $container->get(UserRoleResolver::class),
            ['fake' => 'ROLE_USER_FAKE', 'other' => 'ROLE_USER_OTHER'],
        );
        // Set before anything builds the collector, which then gets it with the real sections
        $container->set(GameProfileRegistry::class, $registry);
        // No refresh counted by another test or run
        $container->get('cache.rate_limiter')->clear();

        $this->user = $this->createUser($roles);
        $this->logIn($client, $this->user);
        $this->fake->profiles = [FakeGameProfile::ownedBy($this->user, 5)];

        return $client;
    }

    private function getStatus(KernelBrowser $client, string $path): void
    {
        $client->request('GET', self::url('games.domain_name', $path));
    }

    public function testOwnerGetsTheStatusOfTheProfile(): void
    {
        $client = $this->createStatusClient();

        $this->getStatus($client, '/api/fake/gameProfiles/5/status');

        $this->assertResponseIsSuccessful();
        $json = self::jsonResponse($client);
        $this->assertSame('fake:5', $json['ref']);
        $this->assertSame(['slug' => 'fg', 'name' => 'Game fake_game'], $json['game']);
        $this->assertSame(['ok' => true, 'problem' => null], $json['account']);
        $this->assertNull($json['error']);
        $this->assertEquals($this->service->fetchedAt, new \DateTimeImmutable($json['fetched_at']));
        $this->assertGreaterThan(new \DateTimeImmutable($json['fetched_at']), new \DateTimeImmutable($json['generated_at']));
        // Cached reads: the notes are read once, without skipping the cache
        $this->assertSame([false], $this->service->notesCalls);

        $this->assertSame(['stamina', 'dailies'], array_column($json['sections'], 'key'));
        [$stamina, $dailies] = $json['sections'];
        $this->assertSame([
            'key' => 'stamina',
            'label' => 'Stamina',
            'icon' => '🔋',
            'available' => true,
            'attention' => false,
            'value' => $stamina['value'],
            'description' => null,
            'fields' => $stamina['fields'],
        ], $stamina);
        $this->assertStringStartsWith('120/200', $stamina['value']);
        // The full view of the bot without its profile header (player, region, rank)
        $this->assertSame(['name' => 'Stamina', 'value' => '120/200', 'icon' => '🔋', 'inline' => true], $stamina['fields'][0]);
        $this->assertNotContains('Player', array_column($stamina['fields'], 'name'));
        $this->assertTrue($dailies['available']);
        $this->assertTrue($dailies['attention'], '2/4 dailies are something to do');
        $this->assertSame('Daily Tasks', $dailies['fields'][0]['name']);
    }

    public function testFailedSectionIsGeneric(): void
    {
        $client = $this->createStatusClient();
        $this->service->dailiesError = new \RuntimeException('GET https://api.example.test/notes?cookie=secret failed');

        $this->getStatus($client, '/api/fake/gameProfiles/5/status');

        $this->assertResponseIsSuccessful();
        $json = self::jsonResponse($client);
        $this->assertTrue($json['sections'][0]['available']);
        $this->assertSame([
            'key' => 'dailies',
            'label' => 'Dailies',
            'icon' => $json['sections'][1]['icon'],
            'available' => false,
            'attention' => false,
            'value' => ProfileSummaryPresenter::UNAVAILABLE,
            'description' => null,
            'fields' => [],
        ], $json['sections'][1]);
        $this->assertStringNotContainsString('secret', (string) $client->getResponse()->getContent());
    }

    public function testUnreadableProfileAndAccountProblem(): void
    {
        $client = $this->createStatusClient();
        $this->fake->contextError = new \RuntimeException('cookie=secret');
        $this->fake->health = AccountHealth::problem('Log in again on the site.');

        $this->getStatus($client, '/api/fake/gameProfiles/5/status');

        $this->assertResponseIsSuccessful();
        $json = self::jsonResponse($client);
        $this->assertSame(ProfileSummaryPresenter::PROFILE_UNAVAILABLE, $json['error']);
        $this->assertSame([], $json['sections']);
        $this->assertSame(['ok' => false, 'problem' => 'Log in again on the site.'], $json['account']);
        $this->assertStringNotContainsString('secret', (string) $client->getResponse()->getContent());
    }

    public function testProfileOfAnotherUserIsNotFound(): void
    {
        $client = $this->createStatusClient();
        $someoneElse = (new User())->setEmail('other@example.test')->setPassword('x');
        (new \ReflectionProperty(User::class, 'id'))->setValue($someoneElse, 7);
        $this->fake->profiles[] = FakeGameProfile::ownedBy($someoneElse, 6, accountId: 2);

        $this->getStatus($client, '/api/fake/gameProfiles/6/status');

        $this->assertResponseStatusCodeSame(404);
        $this->assertSame([], $this->service->notesCalls);
    }

    public function testProviderWithoutRoleOrUnknownIsNotFound(): void
    {
        $client = $this->createStatusClient();
        $this->other->profiles = [new OtherFakeGameProfile(FakeGameProfile::ownedBy($this->user, 5))];

        $this->getStatus($client, '/api/other/gameProfiles/5/status');
        $this->assertResponseStatusCodeSame(404);

        $this->getStatus($client, '/api/nope/gameProfiles/5/status');
        $this->assertResponseStatusCodeSame(404);

        $this->getStatus($client, '/api/fake/gameProfiles/404/status');
        $this->assertResponseStatusCodeSame(404);

        $this->assertSame([], $this->service->notesCalls);
    }

    public function testGamesRoleIsRequired(): void
    {
        $client = $this->createStatusClient(['ROLE_USER_FAKE']);

        $this->getStatus($client, '/api/fake/gameProfiles/5/status');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testFreshReadsAreLimitedPerProfile(): void
    {
        $client = $this->createStatusClient();
        $this->fake->profiles[] = FakeGameProfile::ownedBy($this->user, 8);

        for ($i = 0; $i < 3; $i++) {
            $this->getStatus($client, '/api/fake/gameProfiles/5/status?fresh=1');
            $this->assertResponseIsSuccessful();
        }
        $this->assertSame([true, true, true], $this->service->notesCalls);

        $this->getStatus($client, '/api/fake/gameProfiles/5/status?fresh=1');
        $this->assertResponseStatusCodeSame(429);
        $this->assertGreaterThan(0, (int) $client->getResponse()->headers->get('Retry-After'));
        $this->assertSame('Too many refreshes of this profile: try again in a few minutes.', self::jsonResponse($client)['detail']);
        $this->assertCount(3, $this->service->notesCalls, 'A refused refresh reads nothing');

        // Cached reads and the other profiles are not limited
        $this->getStatus($client, '/api/fake/gameProfiles/5/status');
        $this->assertResponseIsSuccessful();
        $this->getStatus($client, '/api/fake/gameProfiles/8/status?fresh=1');
        $this->assertResponseIsSuccessful();
    }

    public function testFreshReadOfAnotherUserIsNotFoundNotLimited(): void
    {
        $client = $this->createStatusClient();
        $someoneElse = (new User())->setEmail('other@example.test')->setPassword('x');
        (new \ReflectionProperty(User::class, 'id'))->setValue($someoneElse, 7);
        $this->fake->profiles[] = FakeGameProfile::ownedBy($someoneElse, 6, accountId: 2);

        for ($i = 0; $i < 4; $i++) {
            $this->getStatus($client, '/api/fake/gameProfiles/6/status?fresh=1');
            $this->assertResponseStatusCodeSame(404);
        }
    }
}
