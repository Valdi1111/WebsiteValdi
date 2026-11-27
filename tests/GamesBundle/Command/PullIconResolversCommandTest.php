<?php

namespace App\Tests\GamesBundle\Command;

use App\GamesBundle\Command\PullIconResolversCommand;
use App\GamesBundle\Model\Game\PullItemIconQuery;
use App\GamesBundle\Model\Game\PullItemIconResolverInterface;
use App\GamesBundle\Model\Game\PullItemPreviewResolverInterface;
use App\GamesBundle\Service\PullItemIconResolverManager;
use App\HoyoverseBundle\Service\HonkaiStarRailService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class PullIconResolversCommandTest extends TestCase
{
    public function testShowsTheIconAndTheArtworkOfEachResolver(): void
    {
        $resolver = new class implements PullItemPreviewResolverInterface {
            public static function getSupportedGameServices(): array
            {
                return [HonkaiStarRailService::class => 100];
            }

            public function resolveUrl(string|object $gameService, PullItemIconQuery $query): ?string
            {
                return 'https://cdn.example/icon/' . $query->itemId . '.webp';
            }

            public function resolvePreviewUrl(string|object $gameService, PullItemIconQuery $query): ?string
            {
                return 'https://cdn.example/art/' . $query->itemId . '.webp';
            }
        };
        $httpClient = new MockHttpClient(static fn (): MockResponse => new MockResponse('IMG', [
            'http_code' => 200,
            'response_headers' => ['content-type' => 'image/webp'],
        ]));

        $tester = new CommandTester(new PullIconResolversCommand(new PullItemIconResolverManager([$resolver]), $httpClient));
        $tester->execute(['--game' => 'hkrpg_global', '--id' => '1102', '--check-http' => true]);

        $tester->assertCommandIsSuccessful();
        $output = $tester->getDisplay();
        $this->assertStringContainsString('https://cdn.example/icon/1102.webp', $output);
        $this->assertStringContainsString('https://cdn.example/art/1102.webp', $output);
        $this->assertStringContainsString('HTTP 200 (image/webp, 3 B)', $output);
    }

    public function testSaysWhenNoResolverHasArtwork(): void
    {
        $resolver = new class implements PullItemIconResolverInterface {
            public static function getSupportedGameServices(): array
            {
                return [HonkaiStarRailService::class => 100];
            }

            public function resolveUrl(string|object $gameService, PullItemIconQuery $query): ?string
            {
                return 'https://cdn.example/icon.webp';
            }
        };

        $tester = new CommandTester(new PullIconResolversCommand(new PullItemIconResolverManager([$resolver]), new MockHttpClient()));
        $tester->execute(['--game' => 'hkrpg_global', '--id' => '1102']);

        $tester->assertCommandIsSuccessful();
        $this->assertStringContainsString('Nessun provider con artwork', $tester->getDisplay());
    }
}
