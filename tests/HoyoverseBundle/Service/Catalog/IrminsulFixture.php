<?php

namespace App\Tests\HoyoverseBundle\Service\Catalog;

use App\GamesBundle\Service\Irminsul\IrminsulClient;
use App\GamesBundle\Service\Irminsul\IrminsulPhaseNormalizer;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Tiny fake irminsul.gg data API served through MockHttpClient: two phases per category and game, with their
 * rate-up items, copied from the real files (item rows reduced to the fields the builder reads).
 */
final class IrminsulFixture
{
    public const string BASE_URI = 'https://api.irminsul.gg';

    /** @var list<string> paths requested on the irminsul host */
    public array $requestedPaths = [];

    /** @var array<string, mixed> path ("v2/hsr/characters.json") => decoded JSON (or raw string); null = 404 */
    public array $files;

    /**
     * @param array<string, mixed> $files path => decoded JSON (or raw string); null = 404
     */
    public function __construct(array $files = [])
    {
        $this->files = array_replace(self::defaultFiles(), $files);
    }

    public function client(): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url): MockResponse {
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
        }, self::BASE_URI);
    }

    public function irminsulClient(): IrminsulClient
    {
        return new IrminsulClient($this->client());
    }

    public static function normalizer(): IrminsulPhaseNormalizer
    {
        return new IrminsulPhaseNormalizer(new NullLogger());
    }

    /**
     * @return array<string, mixed>
     */
    public static function item(int $id, string $name, string $displayName, int $rarity): array
    {
        return ['id' => $id, 'name' => $name, 'displayName' => $displayName, 'rarity' => $rarity, 'release' => ['version' => '1.0']];
    }

    /**
     * @param list<string> $rateUps
     *
     * @return array<string, mixed>
     */
    public static function banner(int $id, string $version, string $start, string $end, array $rateUps, ?string $title = null): array
    {
        return array_filter(['id' => $id, 'version' => $version, 'title' => $title, 'start' => $start, 'end' => $end, 'rateUps' => $rateUps], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaultFiles(): array
    {
        return [
            'v2/genshin/characters.json' => [
                self::item(10000033, 'Tartaglia', 'Tartaglia', 5),
                self::item(10000030, 'Zhongli', 'Zhongli', 5),
                self::item(10000024, 'Beidou', 'Beidou', 4),
                self::item(10000039, 'Diona', 'Diona', 4),
                self::item(10000027, 'Ningguang', 'Ningguang', 4),
                self::item(10000036, 'Chongyun', 'Chongyun', 4),
                self::item(10000020, 'Razor', 'Razor', 4),
                self::item(10000044, 'Xinyan', 'Xinyan', 4),
                self::item(10000054, 'Anemo Traveler', 'Traveler (Anemo)', 5),
                // No name: skipped
                ['id' => 10000099, 'displayName' => 'Nameless', 'rarity' => 4],
            ],
            'v2/genshin/weapons.json' => [
                self::item(15501, 'Skyward Harp', 'Skyward Harp', 5),
                self::item(14504, 'Memory of Dust', 'Memory of Dust', 5),
                self::item(15405, 'Rust', 'Rust', 4),
                self::item(13504, 'Vortex Vanquisher', 'Vortex Vanquisher', 5),
                self::item(12504, 'The Unforged', 'The Unforged', 5),
                self::item(15401, 'Favonius Warbow', 'Favonius Warbow', 4),
                self::item(15101, "Hunter's Bow", "Hunter's Bow", 1),
            ],
            'v2/genshin/banner-characters.json' => [
                self::banner(10002, '1.1.1', '2020-11-11 10:00:00 UTC+8', '2020-12-01 15:59:59', ['Tartaglia', 'Beidou', 'Diona', 'Ningguang']),
                self::banner(10003, '1.1.2', '2020-12-01 18:00:00', '2020-12-22 14:59:59', ['Zhongli', 'Chongyun', 'Razor', 'Xinyan']),
            ],
            'v2/genshin/banner-weapons.json' => [
                // Rate-ups reduced to the items of the fixture
                self::banner(20002, '1.1.1', '2020-11-11 10:00:00 UTC+8', '2020-12-01 15:59:59', ['Skyward Harp', 'Memory of Dust', 'Rust']),
                self::banner(20003, '1.1.2', '2020-12-01 18:00:00', '2020-12-22 14:59:59', ['Vortex Vanquisher', 'The Unforged', 'Favonius Warbow']),
            ],
            'v2/hsr/characters.json' => [
                self::item(1102, 'Seele', 'Seele', 5),
                self::item(1109, 'Hook', 'Hook', 4),
                self::item(1105, 'Natasha', 'Natasha', 4),
                self::item(1106, 'Pela', 'Pela', 4),
                self::item(1204, 'Jing Yuan', 'Jing Yuan', 5),
                self::item(1001, 'March 7th Preservation', 'March 7th (Preservation)', 4),
                self::item(1206, 'Sushang', 'Sushang', 4),
                self::item(1202, 'Tingyun', 'Tingyun', 4),
                self::item(8001, 'Trailblazer', 'Trailblazer (Destruction)', 5),
                self::item(8003, 'Trailblazer', 'Trailblazer (Preservation)', 5),
            ],
            'v2/hsr/weapons.json' => [
                self::item(23001, 'In the Night', 'In the Night', 5),
                self::item(21001, 'Good Night and Sleep Well', 'Good Night and Sleep Well', 4),
                self::item(23010, 'Before Dawn', 'Before Dawn', 5),
                self::item(21002, 'Day One of My New Life', 'Day One of My New Life', 4),
            ],
            'v2/hsr/banner-characters.json' => [
                self::banner(10000, '1.0.1', '2023-04-25 10:00:00 UTC+8', '2023-05-17 17:59:59', ['Seele', 'Hook', 'Natasha', 'Pela']),
                // "Trailblazer" never is a rate-up: added to show an ambiguous name is skipped
                self::banner(10001, '1.0.2', '2023-05-17 18:00:00', '2023-06-06 14:59:59', ['Jing Yuan', 'March 7th Preservation', 'Sushang', 'Tingyun', 'Trailblazer']),
            ],
            'v2/hsr/banner-weapons.json' => [
                // Rate-ups reduced to the items of the fixture, but "Post-Op Conversation" is left out of the weapons: unknown name
                self::banner(20000, '1.0.1', '2023-04-25 10:00:00 UTC+8', '2023-05-17 17:59:59', ['In the Night', 'Good Night and Sleep Well', 'Post-Op Conversation']),
                self::banner(20001, '1.0.2', '2023-05-17 18:00:00', '2023-06-06 14:59:59', ['Before Dawn', 'Day One of My New Life', 'Post-Op Conversation']),
            ],
            'v2/zzz/characters.json' => [
                self::item(1071, 'Caesar', 'Caesar King', 5),
                self::item(1111, 'Anton', 'Anton Ivanov', 4),
                self::item(1281, 'Piper', 'Piper Wheel', 4),
                self::item(1171, 'Burnice', 'Burnice White', 5),
                self::item(1151, 'Lucy', 'Luciana de Montefio', 4),
                self::item(1031, 'Nicole', 'Nicole Demara', 4),
            ],
            'v2/zzz/weapons.json' => [
                self::item(14107, 'Tusks of Fury', 'Tusks of Fury', 5),
                self::item(13111, 'Drill Rig Red Axis', 'Drill Rig - Red Axis', 4),
                self::item(13128, 'Roaring Ride', 'Roaring Ride', 4),
                self::item(14117, 'Flamemaker Shaker', 'Flamemaker Shaker', 5),
                self::item(13115, 'Kaboom the Cannon', 'Kaboom the Cannon', 4),
                self::item(13103, 'The Vault', 'The Vault', 4),
            ],
            'v2/zzz/banner-characters.json' => [
                self::banner(10004, '1.2.1', '2024-09-25 11:00:00 UTC+8', '2024-10-16 17:59:59', ['Caesar', 'Anton', 'Piper']),
                self::banner(10005, '1.2.2', '2024-10-16 18:00:00', '2024-11-05 14:59:59', ['Burnice', 'Lucy', 'Nicole']),
            ],
            'v2/zzz/banner-weapons.json' => [
                self::banner(20004, '1.2.1', '2024-09-25 11:00:00 UTC+8', '2024-10-16 17:59:59', ['Tusks of Fury', 'Drill Rig Red Axis', 'Roaring Ride']),
                self::banner(20005, '1.2.2', '2024-10-16 18:00:00', '2024-11-05 14:59:59', ['Flamemaker Shaker', 'Kaboom the Cannon', 'The Vault']),
            ],
        ];
    }
}
