<?php

namespace App\Tests\HoyoverseBundle\Service;

use App\HoyoverseBundle\Exception\RetrieveRedeemableCodesException;
use App\HoyoverseBundle\Model\Notes\GenshinImpactNotes;
use App\HoyoverseBundle\Model\Notes\HonkaiStarRailNotes;
use App\HoyoverseBundle\Model\Notes\ZenlessZoneZeroNotes;
use App\HoyoverseBundle\Service\GenshinImpactService;
use App\HoyoverseBundle\Service\HonkaiImpact3rdService;
use App\HoyoverseBundle\Service\HonkaiStarRailService;
use App\HoyoverseBundle\Service\TearsOfThemisService;
use App\HoyoverseBundle\Service\ZenlessZoneZeroService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class HoyoverseGameServiceTest extends TestCase
{
    public function testHonkaiStarRailServiceMetadata(): void
    {
        $service = new HonkaiStarRailService(new NullLogger());

        $this->assertSame(6, HonkaiStarRailService::getGameId());
        $this->assertSame('hkrpg_global', HonkaiStarRailService::getGameBiz());
        $this->assertSame('Honkai: Star Rail', $service->getGameName());
        $this->assertSame(300, $service->getMaxStamina());
        $this->assertSame(360, $service->getRegenRate());
        $this->assertSame(HonkaiStarRailNotes::class, $service->getNotesClass());
        $this->assertStringContainsString('hkrpg', $service->getUrlNotes());
    }

    public function testZenlessZoneZeroServiceMetadata(): void
    {
        $service = new ZenlessZoneZeroService(new NullLogger());

        $this->assertSame(8, ZenlessZoneZeroService::getGameId());
        $this->assertSame('nap_global', ZenlessZoneZeroService::getGameBiz());
        $this->assertSame('Zenless Zone Zero', $service->getGameName());
        $this->assertSame(300, $service->getMaxStamina());
        $this->assertSame(360, $service->getRegenRate());
        $this->assertSame(ZenlessZoneZeroNotes::class, $service->getNotesClass());
        $this->assertStringContainsString('zzz', $service->getUrlNotes());
    }

    public function testGenshinImpactServiceMetadata(): void
    {
        $service = new GenshinImpactService(new NullLogger());

        $this->assertSame(2, GenshinImpactService::getGameId());
        $this->assertSame('hk4e_global', GenshinImpactService::getGameBiz());
        $this->assertSame('Genshin Impact', $service->getGameName());
        $this->assertSame(200, $service->getMaxStamina());
        $this->assertSame(480, $service->getRegenRate());
        $this->assertSame(GenshinImpactNotes::class, $service->getNotesClass());
        $this->assertStringContainsString('genshin', $service->getUrlNotes());
    }

    public function testHonkaiImpact3rdServiceMetadata(): void
    {
        $service = new HonkaiImpact3rdService(new NullLogger());

        $this->assertSame(1, HonkaiImpact3rdService::getGameId());
        $this->assertSame('bh3_global', HonkaiImpact3rdService::getGameBiz());
        $this->assertSame('Honkai Impact 3rd', $service->getGameName());
    }

    public function testTearsOfThemisServiceMetadata(): void
    {
        $service = new TearsOfThemisService(new NullLogger());

        $this->assertSame(0, TearsOfThemisService::getGameId());
        $this->assertSame('nxx_global', TearsOfThemisService::getGameBiz());
        $this->assertSame('Tears of Themis', $service->getGameName());
    }

    public function testMalformedJsonIncludesHttpStatusCodeInLogAndException(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('(HTTP 530)'),
                $this->callback(fn (array $context): bool => ($context['status'] ?? null) === 530
                    && ($context['raw_content'] ?? null) === '<html>Cloudflare 1033</html>')
            );

        $service = new GenshinImpactService($logger);
        $service->setRedeemableCodesClient(new MockHttpClient([
            new MockResponse('<html>Cloudflare 1033</html>', ['http_code' => 530]),
        ]));

        try {
            $service->fetchRedeemableCodes();
            $this->fail('Expected RetrieveRedeemableCodesException to be thrown.');
        } catch (RetrieveRedeemableCodesException $e) {
            $this->assertSame(530, $e->getHoyolabStatusCode());
            $this->assertStringStartsWith('Malformed JSON response (HTTP 530):', $e->getMessage());
        }
    }
}
