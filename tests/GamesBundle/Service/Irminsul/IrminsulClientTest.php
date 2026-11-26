<?php

namespace App\Tests\GamesBundle\Service\Irminsul;

use App\GamesBundle\Service\Irminsul\IrminsulClient;
use App\GamesBundle\Service\Irminsul\IrminsulException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class IrminsulClientTest extends TestCase
{
    private const string BASE_URI = 'https://api.irminsul.gg';

    /** Two real entries of hsr/banner-characters.json */
    private const array BANNERS = [
        ['id' => 10000, 'version' => '1.0.1', 'start' => '2023-04-25 10:00:00 UTC+8', 'end' => '2023-05-17 17:59:59', 'rateUps' => ['Seele', 'Hook', 'Natasha', 'Pela']],
        ['id' => 10001, 'version' => '1.0.2', 'start' => '2023-05-17 18:00:00', 'end' => '2023-06-06 14:59:59', 'rateUps' => ['Jing Yuan', 'March 7th Preservation', 'Sushang', 'Tingyun']],
    ];

    public function testFetchesAndDecodesTheFileOfAGame(): void
    {
        $requests = [];
        $client = new IrminsulClient(new MockHttpClient(static function (string $method, string $url) use (&$requests): MockResponse {
            $requests[] = $method . ' ' . $url;

            return new MockResponse(json_encode(self::BANNERS, \JSON_THROW_ON_ERROR));
        }, self::BASE_URI));

        $this->assertSame(self::BANNERS, $client->fetch('hsr', 'banner-characters'));
        $this->assertSame(['GET https://api.irminsul.gg/v2/hsr/banner-characters.json'], $requests);
    }

    public function testStripsAByteOrderMark(): void
    {
        $client = $this->createClient(new MockResponse("\xEF\xBB\xBF" . json_encode(self::BANNERS, \JSON_THROW_ON_ERROR)));

        $this->assertSame(self::BANNERS, $client->fetch('genshin', 'banner-weapons'));
    }

    /**
     * @return iterable<string, array{MockResponse}>
     */
    public static function unreadableResponseProvider(): iterable
    {
        yield 'not found' => [new MockResponse('<html>Not Found</html>', ['http_code' => 404])];
        yield 'server error' => [new MockResponse('', ['http_code' => 503])];
        yield 'transport error' => [new MockResponse('', ['error' => 'Connection timed out'])];
        yield 'invalid JSON' => [new MockResponse('[{"id": 1,')];
        yield 'empty list' => [new MockResponse('[]')];
        yield 'object instead of a list' => [new MockResponse('{"id": 10000}')];
        yield 'scalar' => [new MockResponse('"maintenance"')];
        yield 'non-object entry' => [new MockResponse('[{"id": 10000}, "oops"]')];
    }

    #[DataProvider('unreadableResponseProvider')]
    public function testUnreadableFilesThrowAnIrminsulException(MockResponse $response): void
    {
        $this->expectException(IrminsulException::class);
        $this->expectExceptionMessage('/v2/zzz/characters.json');

        $this->createClient($response)->fetch('zzz', 'characters');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unknownFileProvider(): iterable
    {
        yield 'unknown game' => ['wuwa', 'characters'];
        yield 'unknown file' => ['genshin', 'banner-chronicled'];
        yield 'path traversal' => ['genshin', '../hsr/characters'];
    }

    #[DataProvider('unknownFileProvider')]
    public function testOnlyKnownGamesAndFilesAreRequested(string $game, string $file): void
    {
        $httpClient = new MockHttpClient(function (): MockResponse {
            $this->fail('No request expected.');
        }, self::BASE_URI);

        $this->expectException(\InvalidArgumentException::class);

        new IrminsulClient($httpClient)->fetch($game, $file);
    }

    private function createClient(MockResponse $response): IrminsulClient
    {
        return new IrminsulClient(new MockHttpClient($response, self::BASE_URI));
    }
}
