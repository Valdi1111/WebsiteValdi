<?php

namespace App\Tests\PerfectworldBundle\Catalog;

use App\GamesBundle\Service\Irminsul\IrminsulClient;
use App\GamesBundle\Service\Irminsul\IrminsulPhaseNormalizer;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Fake irminsul.gg data API for NTE served through MockHttpClient. By default it serves the real files of
 * 2026-11-26: both banner files verbatim (12 character and 12 Arc phases, 1.0.1 to 1.5.2, with the reruns from
 * 1.3.1), the characters reduced to the fields the catalog builder reads.
 */
final class NteIrminsulFixture
{
    public const string BASE_URI = 'https://api.irminsul.gg';
    public const string CHARACTERS = 'v2/nte/characters.json';
    public const string BANNER_CHARACTERS = 'v2/nte/banner-characters.json';
    public const string BANNER_WEAPONS = 'v2/nte/banner-weapons.json';

    /** @var list<string> paths requested on the irminsul host */
    public array $requestedPaths = [];

    /** @var array<string, mixed> path => decoded JSON (or raw string); null = 404 */
    public array $files;

    /**
     * @param array<string, mixed> $files path => decoded JSON (or raw string); null = 404
     */
    public function __construct(array $files = [])
    {
        $this->files = array_replace(self::defaultFiles(), $files);
    }

    public function irminsulClient(): IrminsulClient
    {
        return new IrminsulClient(new MockHttpClient(function (string $method, string $url): MockResponse {
            $path = substr($url, strlen(self::BASE_URI . '/'));
            $this->requestedPaths[] = $path;

            $content = str_starts_with($url, self::BASE_URI . '/') ? ($this->files[$path] ?? null) : null;
            if ($content === null) {
                return new MockResponse('<html>Not Found</html>', ['http_code' => 404]);
            }

            return new MockResponse(
                is_string($content) ? $content : json_encode($content, \JSON_THROW_ON_ERROR),
                ['response_headers' => ['content-type' => 'application/json']],
            );
        }, self::BASE_URI));
    }

    public static function normalizer(): IrminsulPhaseNormalizer
    {
        return new IrminsulPhaseNormalizer(new NullLogger());
    }

    /**
     * Fixture whose every file answers 404.
     */
    public static function down(): self
    {
        return new self(array_fill_keys(array_keys(self::defaultFiles()), null));
    }

    /**
     * @param list<string> $rateUps
     *
     * @return array<string, mixed>
     */
    public static function banner(int $id, string $version, string $start, string $end, array $rateUps): array
    {
        return ['id' => $id, 'version' => $version, 'start' => $start, 'end' => $end, 'rateUps' => $rateUps];
    }

    /**
     * @return array{id: int, name: string, rarity: int}
     */
    public static function character(int $id, string $name, int $rarity): array
    {
        return ['id' => $id, 'name' => $name, 'rarity' => $rarity];
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaultFiles(): array
    {
        return [
            self::CHARACTERS => [
                self::character(1003, 'Sakiri', 5),
                self::character(1008, 'Skia', 4),
                self::character(1010, 'Nanally', 5),
                self::character(1019, 'Mint', 4),
                self::character(1020, 'Haniel', 4),
                self::character(1021, 'Edgar', 4),
                self::character(1023, 'Baicang', 5),
                self::character(1025, 'Hathor', 5),
                self::character(1033, 'Adler', 4),
                self::character(1039, 'Fadia', 5),
                self::character(1051, 'Zero', 5),
                self::character(1054, 'Daffodill', 5),
                self::character(1055, 'Jiuyuan', 5),
                self::character(1070, 'Aurelia', 4),
                self::character(1073, 'Chiz', 5),
                self::character(1052, 'Hotori', 5),
                self::character(1004, 'Lacrimosa', 5),
                self::character(1071, 'Chaos', 5),
                self::character(1075, 'Iroi', 5),
                self::character(1076, 'Shinku', 5),
                self::character(1036, 'Zankou', 5),
                self::character(1072, 'Linko', 5),
                self::character(1042, 'Blackbird', 5),
                self::character(1057, 'Akane Rin', 5),
                self::character(1074, 'Exe', 5),
                self::character(1090, 'Elyms', 5),
            ],
            self::BANNER_CHARACTERS => [
                self::banner(10000, '1.0.1', '2026-04-29 11:00:00 UTC+8', '2026-05-13 05:59:59 UTC+8', ['Nanally']),
                self::banner(10001, '1.0.2', '2026-05-13 11:00:00 UTC+8', '2026-06-03 05:59:59 UTC+8', ['Hotori']),
                self::banner(10002, '1.1.1', '2026-06-03 11:00:00 UTC+8', '2026-06-24 05:59:59 UTC+8', ['Lacrimosa']),
                self::banner(10003, '1.1.2', '2026-06-24 11:00:00 UTC+8', '2026-07-08 05:59:59 UTC+8', ['Chaos']),
                self::banner(10004, '1.2.1', '2026-07-08 11:00:00 UTC+8', '2026-07-29 05:59:59 UTC+8', ['Shinku']),
                self::banner(10005, '1.2.2', '2026-07-29 11:00:00 UTC+8', '2026-08-19 05:59:59 UTC+8', ['Iroi']),
                self::banner(10006, '1.3.1', '2026-08-19 11:00:00 UTC+8', '2026-09-09 05:59:59 UTC+8', ['Zankou', 'Nanally']),
                self::banner(10007, '1.3.2', '2026-09-09 11:00:00 UTC+8', '2026-09-30 05:59:59 UTC+8', ['Linko', 'Hotori']),
                self::banner(10008, '1.4.1', '2026-09-30 11:00:00 UTC+8', '2026-10-21 05:59:59 UTC+8', ['Blackbird', 'Lacrimosa']),
                self::banner(10009, '1.4.2', '2026-10-21 11:00:00 UTC+8', '2026-11-11 05:59:59 UTC+8', ['Akane Rin']),
                self::banner(10010, '1.5.1', '2026-11-11 11:00:00 UTC+8', '2026-12-02 05:59:59 UTC+8', ['Elyms', 'Shinku']),
                self::banner(10011, '1.5.2', '2026-12-02 11:00:00 UTC+8', '2026-12-23 05:59:59 UTC+8', ['Exe', 'Chaos']),
            ],
            self::BANNER_WEAPONS => [
                self::banner(20000, '1.0.1', '2026-04-29 11:00:00 UTC+8', '2026-05-13 05:59:59 UTC+8', ['Ready-Ready']),
                self::banner(20001, '1.0.2', '2026-05-13 11:00:00 UTC+8', '2026-06-03 05:59:59 UTC+8', ['Marching Beyond Time']),
                self::banner(20002, '1.1.1', '2026-06-03 11:00:00 UTC+8', '2026-06-24 05:59:59 UTC+8', ['The Last Rose']),
                self::banner(20003, '1.1.2', '2026-06-24 11:00:00 UTC+8', '2026-07-08 05:59:59 UTC+8', ["What's Desired"]),
                self::banner(20004, '1.2.1', '2026-07-08 11:00:00 UTC+8', '2026-07-29 05:59:59 UTC+8', ['Blushing Mirage']),
                self::banner(20005, '1.2.2', '2026-07-29 11:00:00 UTC+8', '2026-08-19 05:59:59 UTC+8', ['The Wrong Gate']),
                // The real file reuses the ids of the character phases here
                self::banner(10006, '1.3.1', '2026-08-19 11:00:00 UTC+8', '2026-09-09 05:59:59 UTC+8', ['Ravenous Blade', 'Ready-Ready']),
                self::banner(10007, '1.3.2', '2026-09-09 11:00:00 UTC+8', '2026-09-30 05:59:59 UTC+8', ['Voice of the Voyager', 'Marching Beyond Time']),
                self::banner(20008, '1.4.1', '2026-09-30 11:00:00 UTC+8', '2026-10-21 05:59:59 UTC+8', ['Crime and Punishment', 'The Last Rose']),
                self::banner(20009, '1.4.2', '2026-10-21 11:00:00 UTC+8', '2026-11-11 05:59:59 UTC+8', ['Far Side of the Moon']),
                self::banner(20010, '1.5.1', '2026-11-11 11:00:00 UTC+8', '2026-12-02 05:59:59 UTC+8', ['Echoes on Hold', 'Blushing Mirage']),
                self::banner(20011, '1.5.2', '2026-12-02 11:00:00 UTC+8', '2026-12-23 05:59:59 UTC+8', ['Dotted Horizon', "What's Desired"]),
            ],
        ];
    }
}
