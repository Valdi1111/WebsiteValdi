<?php

namespace App\Tests\GamesBundle\Service;

use App\CoreBundle\Entity\User;
use App\GamesBundle\Service\AuthSessionStore;
use App\GamesBundle\Service\GeetestService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

class GamesAuthComponentsTest extends TestCase
{
    private function createMockUser(int $id): User
    {
        $user = new User();
        $ref = new \ReflectionProperty(User::class, 'id');
        $ref->setValue($user, $id);

        return $user;
    }

    public function testAuthSessionStoreCreateGetUpdateDeleteAndOwnership(): void
    {
        $pool = new ArrayAdapter();
        $store = new AuthSessionStore('test-kernel-secret-123456');
        $user1 = $this->createMockUser(1);
        $user2 = $this->createMockUser(2);

        $sessionId = $store->create($pool, $user1, ['step' => 'init']);
        $this->assertNotEmpty($sessionId);

        $loaded = $store->get($pool, $user1, $sessionId);
        $this->assertNotNull($loaded);
        $this->assertSame('init', $loaded['step']);
        $this->assertSame(1, $loaded['userId']);

        $store->update($pool, $user1, $sessionId, ['step' => 'captcha']);
        $updated = $store->get($pool, $user1, $sessionId);
        $this->assertSame('captcha', $updated['step']);

        $this->expectException(AccessDeniedException::class);
        $store->get($pool, $user2, $sessionId);
    }

    public function testAuthSessionStoreEncryptAndDecryptSecret(): void
    {
        $store = new AuthSessionStore('test-kernel-secret-123456');
        $plain = 'MyS3cr3tP@ssw0rd!';

        $encrypted = $store->encryptSecret($plain);
        $this->assertNotSame($plain, $encrypted);
        $this->assertStringNotContainsString($plain, $encrypted);

        $decrypted = $store->decryptSecret($encrypted);
        $this->assertSame($plain, $decrypted);
    }

    public function testGeetestServiceCachesScriptAndRendersFrameHtml(): void
    {
        $callCount = 0;
        $httpClient = new MockHttpClient(function () use (&$callCount) {
            ++$callCount;

            return new MockResponse('window.initGeetest4 = function(){};');
        });
        $cache = new ArrayAdapter();
        $service = new GeetestService($httpClient, $cache, $this->createStub(UrlGeneratorInterface::class));

        $script1 = $service->fetchScript('v4');
        $script2 = $service->fetchScript('v4');

        $this->assertSame('window.initGeetest4 = function(){};', $script1);
        $this->assertSame($script1, $script2);
        $this->assertSame(1, $callCount, 'GeeTest script should be served from cache on second call.');

        $html = $service->renderFrameHtml([
            'version' => 4,
            'captcha_id' => 'test-captcha-id',
            'risk_type' => 'slide',
        ], '/api/accounts/login/geetest-script/v4');

        $this->assertStringContainsString('<meta name="referrer" content="no-referrer" />', $html);
        $this->assertStringContainsString('test-captcha-id', $html);
        $this->assertStringContainsString('/api/accounts/login/geetest-script/v4', $html);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, int, string}>
     */
    public static function frameVersionProvider(): iterable
    {
        yield 'config v3' => [['version' => 3], 4, 'v3'];
        yield 'config v4' => [['version' => 4], 3, 'v4'];
        yield 'missing version uses default v3 (HoYoverse)' => [[], 3, 'v3'];
        yield 'missing version uses default v4 (Gryphline)' => [[], 4, 'v4'];
        yield 'unknown version falls back to default' => [['version' => 9], 3, 'v3'];
    }

    #[DataProvider('frameVersionProvider')]
    public function testCreateFrameResponseUsesSharedScriptRouteAndSecurityHeaders(array $config, int $defaultVersion, string $expectedVersion): void
    {
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects($this->once())
            ->method('generate')
            ->with('games_geetest_script', ['version' => $expectedVersion], UrlGeneratorInterface::ABSOLUTE_PATH)
            ->willReturn('/api/geetest-script/' . $expectedVersion);

        $service = new GeetestService(new MockHttpClient(), new ArrayAdapter(), $urlGenerator);
        $response = $service->createFrameResponse($config + ['captcha_id' => 'gt-id'], $defaultVersion);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringStartsWith('text/html', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('/api/geetest-script/' . $expectedVersion, (string) $response->getContent());
    }
}
