<?php

namespace App\Tests\HoyoverseBundle\Service;

use App\HoyoverseBundle\Entity\HoyoverseGameProfile;
use App\HoyoverseBundle\Model\Game\HasExpeditionsInterface;
use App\HoyoverseBundle\Model\Notes\GameNotesExpedition;
use App\HoyoverseBundle\Model\ParsedCookie;
use App\HoyoverseBundle\Model\RuntimeAccountData;
use App\HoyoverseBundle\Service\GenshinImpactService;
use App\HoyoverseBundle\Service\HonkaiStarRailService;
use App\HoyoverseBundle\Service\HoyolabUtilsService;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * getExpeditionsData() from a real-shaped dailyNote payload, through the application serializer.
 */
class ExpeditionsDataTest extends KernelTestCase
{
    /**
     * @template S of GenshinImpactService|HonkaiStarRailService
     *
     * @param S                    $service
     * @param array<string, mixed> $data    dailyNote "data"
     *
     * @return S
     */
    private function prepare(GenshinImpactService|HonkaiStarRailService $service, array $data): GenshinImpactService|HonkaiStarRailService
    {
        self::bootKernel();

        $service->setDenormalizer(static::getContainer()->get('serializer'));
        $service->setHoyolabUtils(new HoyolabUtilsService());
        $service->setNotesCache(new ArrayAdapter());
        $service->setNotesClock(new MockClock());
        $service->setHoyolabClient(new MockHttpClient(new MockResponse(
            json_encode(['retcode' => 0, 'message' => 'OK', 'data' => $data], JSON_THROW_ON_ERROR),
            ['response_headers' => ['content-type' => 'application/json']]
        )));

        return $service;
    }

    private static function account(int $gameId): RuntimeAccountData
    {
        return new RuntimeAccountData()
            ->setParsedCookie(new ParsedCookie()->setLtokenV2('v2_test')->setLtuidV2('100000001')->setLtmidV2('mid_test'))
            ->setGameProfile(new HoyoverseGameProfile()->setGameId($gameId)->setGameUid('700000001')->setRegion('os_euro'));
    }

    /**
     * @return list<array{0: ?string, 1: ?string, 2: ?int}> avatar, status and remaining time of each expedition
     */
    private static function expeditions(HasExpeditionsInterface $service, int $gameId): array
    {
        return array_map(
            static fn (GameNotesExpedition $expedition) => [$expedition->getAvatar(), $expedition->getStatus(), $expedition->getRemainingTime()],
            $service->getExpeditionsData(self::account($gameId))->toArray()
        );
    }

    public function testHonkaiStarRailAssignmentsKeepTheirFirstAvatar(): void
    {
        $service = $this->prepare(new HonkaiStarRailService(new NullLogger()), [
            'accepted_epedition_num' => 2,
            'total_expedition_num' => 4,
            'expeditions' => [
                ['avatars' => ['https://example.test/a1.png', 'https://example.test/a2.png'], 'status' => 'Finished', 'remaining_time' => 0, 'name' => 'Nameless Land'],
                ['avatars' => ['https://example.test/a3.png'], 'status' => 'Ongoing', 'remaining_time' => 7200, 'name' => 'Root Out the Turpitude'],
            ],
        ]);

        $this->assertSame([
            ['https://example.test/a1.png', 'finished', 0],
            ['https://example.test/a3.png', 'ongoing', 7200],
        ], self::expeditions($service, HonkaiStarRailService::GAME_ID));
    }

    public function testHonkaiStarRailAssignmentWithoutAvatars(): void
    {
        $service = $this->prepare(new HonkaiStarRailService(new NullLogger()), [
            'expeditions' => [['avatars' => [], 'status' => 'Ongoing', 'remaining_time' => 60]],
        ]);

        $this->assertSame([[null, 'ongoing', 60]], self::expeditions($service, HonkaiStarRailService::GAME_ID));
    }

    public function testGenshinImpactRemainingTimeIsAnInteger(): void
    {
        $service = $this->prepare(new GenshinImpactService(new NullLogger()), [
            'current_expedition_num' => 2,
            'max_expedition_num' => 5,
            'expeditions' => [
                ['avatar_side_icon' => 'https://example.test/side1.png', 'status' => 'Finished', 'remained_time' => '0'],
                ['avatar_side_icon' => 'https://example.test/side2.png', 'status' => 'Ongoing', 'remained_time' => '3600'],
            ],
        ]);

        $this->assertSame([
            ['https://example.test/side1.png', 'finished', 0],
            ['https://example.test/side2.png', 'ongoing', 3600],
        ], self::expeditions($service, GenshinImpactService::GAME_ID));
    }

    public function testNoExpeditionsInThePayload(): void
    {
        $hsr = $this->prepare(new HonkaiStarRailService(new NullLogger()), ['current_stamina' => 10]);
        $this->assertSame([], self::expeditions($hsr, HonkaiStarRailService::GAME_ID));

        $genshin = $this->prepare(new GenshinImpactService(new NullLogger()), ['current_resin' => 10]);
        $this->assertSame([], self::expeditions($genshin, GenshinImpactService::GAME_ID));
    }
}
