<?php

namespace App\Tests\HoyoverseBundle\Service\Catalog;

use App\GamesBundle\Service\Irminsul\IrminsulException;
use App\HoyoverseBundle\Service\Catalog\GiCatalog;
use App\HoyoverseBundle\Service\Catalog\GiCatalogBuilder;
use App\HoyoverseBundle\Service\Catalog\HsrCatalog;
use App\HoyoverseBundle\Service\Catalog\HsrCatalogBuilder;
use App\HoyoverseBundle\Service\Catalog\ZzzCatalogBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

class IrminsulCatalogBuilderTest extends TestCase
{
    public function testGenshinCatalogHasTheItemsAndThePhasesWithTheirRateUps(): void
    {
        $fixture = new IrminsulFixture();
        $before = new \DateTimeImmutable('-1 second');

        $catalog = new GiCatalogBuilder($fixture->irminsulClient(), IrminsulFixture::normalizer(), new NullLogger())->build();

        $this->assertSame(['v2/genshin/characters.json', 'v2/genshin/weapons.json', 'v2/genshin/banner-characters.json', 'v2/genshin/banner-weapons.json'], $fixture->requestedPaths);
        $this->assertGreaterThanOrEqual($before, \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $catalog['fetched_at']));

        // Characters and weapons keyed by irminsul id; the entry without a name is skipped
        $this->assertCount(16, $catalog['items']);
        $this->assertSame(
            ['id' => '10000054', 'name' => 'Anemo Traveler', 'display_name' => 'Traveler (Anemo)', 'rarity' => 5, 'category' => 'character', 'release' => '1.0'],
            $catalog['items']['10000054'],
        );
        $this->assertSame(
            ['id' => '15101', 'name' => "Hunter's Bow", 'display_name' => "Hunter's Bow", 'rarity' => 1, 'category' => 'weapon', 'release' => '1.0'],
            $catalog['items']['15101'],
        );
        $this->assertArrayNotHasKey('10000099', $catalog['items']);

        $this->assertSame([
            [
                'id' => 10002,
                'category' => 'character',
                'version' => '1.1.1',
                'title' => null,
                'start' => ['at' => '2020-11-11 10:00:00', 'offset' => 8],
                // First-phase end moved to just before the 18:00 phase change
                'end' => ['at' => '2020-12-01 17:59:59', 'offset' => null],
                'up' => ['10000033'],
                'up_a' => ['10000024', '10000039', '10000027'],
            ],
            [
                'id' => 10003,
                'category' => 'character',
                'version' => '1.1.2',
                'title' => null,
                'start' => ['at' => '2020-12-01 18:00:00', 'offset' => null],
                'end' => ['at' => '2020-12-22 14:59:59', 'offset' => null],
                'up' => ['10000030'],
                'up_a' => ['10000036', '10000020', '10000044'],
            ],
            [
                'id' => 20002,
                'category' => 'weapon',
                'version' => '1.1.1',
                'title' => null,
                'start' => ['at' => '2020-11-11 10:00:00', 'offset' => 8],
                'end' => ['at' => '2020-12-01 17:59:59', 'offset' => null],
                'up' => ['15501', '14504'],
                'up_a' => ['15405'],
            ],
            [
                'id' => 20003,
                'category' => 'weapon',
                'version' => '1.1.2',
                'title' => null,
                'start' => ['at' => '2020-12-01 18:00:00', 'offset' => null],
                'end' => ['at' => '2020-12-22 14:59:59', 'offset' => null],
                'up' => ['13504', '12504'],
                'up_a' => ['15401'],
            ],
        ], $catalog['phases']);

        // The lead's read side accepts the shape as is
        $reader = new GiCatalog(new ArrayAdapter());
        $reader->save($catalog);
        $this->assertSame($catalog['phases'], $reader->all()['phases']);
        $this->assertSame('10000054', $reader->findItemByName('Traveler (Anemo)')['id']);
    }

    public function testHsrSecondPhaseStartsAtNoonAndUnresolvedNamesAreSkippedOnce(): void
    {
        $logs = [];
        $catalog = new HsrCatalogBuilder(new IrminsulFixture()->irminsulClient(), IrminsulFixture::normalizer(), self::collectingLogger($logs))->build();

        $phases = new HsrCatalog(new ArrayAdapter());
        $phases->save($catalog);
        [$first, $second] = $phases->getPhases('character');
        $this->assertSame(['at' => '2023-05-17 11:59:59', 'offset' => null], $first['end']);
        $this->assertSame(['at' => '2023-05-17 12:00:00', 'offset' => null], $second['start']);
        $this->assertSame(['1102'], $first['up']);
        $this->assertSame(['1109', '1105', '1106'], $first['up_a']);
        // "Trailblazer" names two items: skipped
        $this->assertSame(['1204'], $second['up']);
        $this->assertSame(['1001', '1206', '1202'], $second['up_a']);

        [$firstWeapon, $secondWeapon] = $phases->getPhases('weapon');
        $this->assertSame(['23001'], $firstWeapon['up']);
        $this->assertSame(['21001'], $firstWeapon['up_a']);
        $this->assertSame(['23010'], $secondWeapon['up']);
        $this->assertSame(['21002'], $secondWeapon['up_a']);

        $warnings = array_values(array_filter($logs, static fn (array $log): bool => $log['level'] === LogLevel::WARNING));
        $this->assertSame(
            [['Trailblazer', 'character', '1.0.2'], ['Post-Op Conversation', 'weapon', '1.0.1']],
            array_map(static fn (array $log): array => [$log['context']['name'], $log['context']['category'], $log['context']['version']], $warnings),
        );
        $this->assertStringContainsString('several items have it', $warnings[0]['message']);
        $this->assertStringContainsString('no item has it', $warnings[1]['message']);
    }

    public function testZzzSecondPhaseStartsAtNoon(): void
    {
        $catalog = new ZzzCatalogBuilder(new IrminsulFixture()->irminsulClient(), IrminsulFixture::normalizer(), new NullLogger())->build();

        $this->assertSame(['at' => '2024-10-16 11:59:59', 'offset' => null], $catalog['phases'][0]['end']);
        $this->assertSame(['at' => '2024-10-16 12:00:00', 'offset' => null], $catalog['phases'][1]['start']);
        $this->assertSame(['1171'], $catalog['phases'][1]['up']);
        $this->assertSame(['1151', '1031'], $catalog['phases'][1]['up_a']);
        $this->assertSame(['14117'], $catalog['phases'][3]['up']);
        $this->assertSame('Drill Rig - Red Axis', $catalog['items']['13111']['display_name']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function failedBuildProvider(): iterable
    {
        yield 'items unavailable' => [['v2/hsr/characters.json' => null], 'characters.json could not be read'];
        yield 'banners unavailable' => [['v2/hsr/banner-weapons.json' => null], 'banner-weapons.json could not be read'];
        yield 'invalid JSON' => [['v2/hsr/weapons.json' => '[{"id": 23001,'], 'weapons.json is not valid JSON'];
        yield 'no usable item' => [['v2/hsr/weapons.json' => [['id' => 23001, 'name' => '']]], 'weapons.json has no usable item'];
        yield 'no usable phase' => [['v2/hsr/banner-characters.json' => [['id' => 10000, 'version' => '1.0.1', 'start' => 'soon', 'end' => 'later', 'rateUps' => []]]], 'banner-characters.json has no usable phase'];
    }

    /**
     * @param array<string, mixed> $files
     */
    #[DataProvider('failedBuildProvider')]
    public function testUnusableSourceAbortsTheBuild(array $files, string $message): void
    {
        $this->expectException(IrminsulException::class);
        $this->expectExceptionMessage($message);

        new HsrCatalogBuilder(new IrminsulFixture($files)->irminsulClient(), IrminsulFixture::normalizer(), new NullLogger())->build();
    }

    /**
     * @param list<array{level: string, message: string, context: array<string, mixed>}> $logs
     */
    private static function collectingLogger(array &$logs): LoggerInterface
    {
        return new class($logs) extends AbstractLogger {
            /**
             * @param list<array{level: string, message: string, context: array<string, mixed>}> $logs
             */
            public function __construct(private array &$logs)
            {
            }

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->logs[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
            }
        };
    }
}
