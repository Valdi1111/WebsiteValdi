<?php

namespace App\Tests\GamesBundle\Service\Irminsul;

use App\GamesBundle\Service\Irminsul\IrminsulPhaseNormalizer;
use App\GamesBundle\Service\Irminsul\IrminsulTimeRules;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

/**
 * Banner entries copied or adapted from the irminsul.gg data files (rate-ups shortened where irrelevant).
 */
class IrminsulPhaseNormalizerTest extends TestCase
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    private array $logs = [];

    private IrminsulPhaseNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->logs = [];
        $this->normalizer = new IrminsulPhaseNormalizer(new class($this->logs) extends AbstractLogger {
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
        });
    }

    public function testHsrSecondPhaseStartsAtNoonAndTheFirstEndsJustBefore(): void
    {
        $phases = $this->normalizer->normalize([
            ['id' => 10000, 'version' => '1.0.1', 'start' => '2023-04-25 10:00:00 UTC+8', 'end' => '2023-05-17 17:59:59', 'rateUps' => ['Seele', 'Hook', 'Natasha', 'Pela']],
            ['id' => 10001, 'version' => '1.0.2', 'start' => '2023-05-17 18:00:00', 'end' => '2023-06-06 14:59:59', 'rateUps' => ['Jing Yuan', 'March 7th Preservation', 'Sushang', 'Tingyun']],
            ['id' => 10002, 'version' => '1.1.1', 'start' => '2023-06-07 11:00:00 UTC+8', 'end' => '2023-06-28 11:59:59', 'rateUps' => ['Silver Wolf']],
            ['id' => 10003, 'version' => '1.1.2', 'start' => '2023-06-28 12:00:00', 'end' => '2023-07-18 14:59:59', 'rateUps' => ['Luocha']],
        ], 'character', IrminsulTimeRules::hoyoverse('12:00:00'));

        $this->assertSame([
            [
                'id' => 10000,
                'category' => 'character',
                'version' => '1.0.1',
                'title' => null,
                'start' => ['at' => '2023-04-25 10:00:00', 'offset' => 8],
                'end' => ['at' => '2023-05-17 11:59:59', 'offset' => null],
                'rate_ups' => ['Seele', 'Hook', 'Natasha', 'Pela'],
            ],
            [
                'id' => 10001,
                'category' => 'character',
                'version' => '1.0.2',
                'title' => null,
                'start' => ['at' => '2023-05-17 12:00:00', 'offset' => null],
                'end' => ['at' => '2023-06-06 14:59:59', 'offset' => null],
                'rate_ups' => ['Jing Yuan', 'March 7th Preservation', 'Sushang', 'Tingyun'],
            ],
        ], array_slice($phases, 0, 2));

        // 1.1.x already has the right times: nothing to rewrite
        $this->assertSame(['at' => '2023-06-28 11:59:59', 'offset' => null], $phases[2]['end']);
        $this->assertSame(['at' => '2023-06-28 12:00:00', 'offset' => null], $phases[3]['start']);
        $this->assertSame([
            [LogLevel::INFO, '1.0.1', '2023-05-17 17:59:59', '2023-05-17 11:59:59'],
            [LogLevel::INFO, '1.0.2', '2023-05-17 18:00:00', '2023-05-17 12:00:00'],
        ], $this->logSummary());
    }

    public function testZzz122StartsAtNoon(): void
    {
        $phases = $this->normalizer->normalize([
            ['id' => 20004, 'version' => '1.2.1', 'start' => '2024-09-25 11:00:00 UTC+8', 'end' => '2024-10-16 17:59:59', 'rateUps' => ['Tusks of Fury', 'Drill Rig Red Axis', 'Roaring Ride']],
            ['id' => 20005, 'version' => '1.2.2', 'start' => '2024-10-16 18:00:00', 'end' => '2024-11-05 14:59:59', 'rateUps' => ['Flamemaker Shaker', 'Kaboom the Cannon', 'The Vault']],
        ], 'weapon', IrminsulTimeRules::hoyoverse('12:00:00'));

        $this->assertSame(['at' => '2024-10-16 11:59:59', 'offset' => null], $phases[0]['end']);
        $this->assertSame(['at' => '2024-10-16 12:00:00', 'offset' => null], $phases[1]['start']);
        $this->assertSame('weapon', $phases[1]['category']);
    }

    public function testGenshinFirstPhaseEndingAt155959EndsJustBeforeSixPm(): void
    {
        $phases = $this->normalizer->normalize([
            ['id' => 10000, 'version' => '1.0.1', 'start' => '2020-09-28 10:00:00 UTC+8', 'end' => '2020-10-18 17:59:59', 'rateUps' => ['Venti']],
            ['id' => 10001, 'version' => '1.0.2', 'start' => '2020-10-20 18:00:00', 'end' => '2020-11-10 14:59:59', 'rateUps' => ['Klee']],
            ['id' => 10002, 'version' => '1.1.1', 'start' => '2020-11-11 10:00:00 UTC+8', 'end' => '2020-12-01 15:59:59', 'rateUps' => ['Tartaglia']],
            ['id' => 10003, 'version' => '1.1.2', 'start' => '2020-12-01 18:00:00', 'end' => '2020-12-22 14:59:59', 'rateUps' => ['Zhongli']],
        ], 'character', IrminsulTimeRules::hoyoverse('18:00:00'));

        // 1.0.1 ended two days before 1.0.2 started: its end is not a phase change
        $this->assertSame(['at' => '2020-10-18 17:59:59', 'offset' => null], $phases[0]['end']);
        $this->assertSame(['at' => '2020-10-20 18:00:00', 'offset' => null], $phases[1]['start']);
        $this->assertSame(['at' => '2020-12-01 17:59:59', 'offset' => null], $phases[2]['end']);
        $this->assertSame([[LogLevel::INFO, '1.1.1', '2020-12-01 15:59:59', '2020-12-01 17:59:59']], $this->logSummary());
    }

    public function testFirstPhaseIsTheLastVersionComponentOne(): void
    {
        // Read with a noon phase two time, so that every phase change is rewritten
        $phases = $this->normalizer->normalize([
            ['id' => 10087, 'version' => 'Luna I.1', 'start' => '2025-09-10 11:00:00 UTC+8', 'end' => '2025-09-30 17:59:59', 'rateUps' => ['Lauma']],
            ['id' => 10088, 'version' => 'Luna I.2', 'start' => '2025-09-30 18:00:00', 'end' => '2025-10-21 14:59:59', 'rateUps' => ['Flins']],
            // Endfield versions: "1.6.11" is not a first phase, so it moves to the phase two time and 1.6.1 ends just before it
            ['id' => 10013, 'version' => '1.6.1', 'start' => '2026-10-15 11:00:00 UTC+8', 'end' => '2026-10-29 17:59:59', 'rateUps' => ['Lunar Calling']],
            ['id' => 10014, 'version' => '1.6.11', 'title' => 'RE-Factor Headhunting', 'start' => '2026-10-29 18:00:00', 'end' => '2026-11-19 14:59:59', 'rateUps' => ['Laevatain']],
        ], 'character', IrminsulTimeRules::hoyoverse('12:00:00'));

        $this->assertSame('2025-09-30 11:59:59', $phases[0]['end']['at']);
        $this->assertSame('2025-09-30 12:00:00', $phases[1]['start']['at']);
        $this->assertSame('2026-10-29 11:59:59', $phases[2]['end']['at']);
        $this->assertSame('2026-10-29 12:00:00', $phases[3]['start']['at']);
    }

    public function testZonedTimesAreNeverRewrittenByTheHoyoverseRules(): void
    {
        // HSR 4.2.x with zoned times
        $phases = $this->normalizer->normalize([
            ['id' => 10053, 'version' => '4.2.1', 'start' => '2026-04-22 11:00:00 UTC+8', 'end' => '2026-05-13 17:59:59 UTC+8', 'rateUps' => ['Silver Wolf LV.999']],
            ['id' => 10054, 'version' => '4.2.2', 'start' => '2026-05-13 18:00:00 UTC+8', 'end' => '2026-06-01 03:59:59 UTC+8', 'rateUps' => ['Evanescia']],
        ], 'character', IrminsulTimeRules::hoyoverse('12:00:00'));

        $this->assertSame(['at' => '2026-05-13 17:59:59', 'offset' => 8], $phases[0]['end']);
        $this->assertSame(['at' => '2026-05-13 18:00:00', 'offset' => 8], $phases[1]['start']);
        $this->assertSame([], $this->logs);
    }

    public function testEndfieldEndsAtNoonMinusOneSecondInUtcMinusFive(): void
    {
        $phases = $this->normalizer->normalize([
            ['id' => 10000, 'version' => '1.0.1', 'start' => '2026-01-22 11:00:00 UTC+8', 'end' => '2026-02-07 11:59:59', 'rateUps' => ['Laevatain']],
            ['id' => 10002, 'version' => '1.0.3', 'start' => '2026-02-24 12:00:00', 'end' => '2026-03-11 16:59:59', 'rateUps' => ['Yvonne']],
            ['id' => 10017, 'version' => '1.7.2', 'title' => 'RE-Factor Headhunting', 'start' => '2026-11-25 11:00:00 UTC+8', 'end' => '2026-12-16 11:59:59', 'rateUps' => ['Gilberta']],
        ], 'character', IrminsulTimeRules::endfield());

        $this->assertSame(['at' => '2026-01-22 11:00:00', 'offset' => 8], $phases[0]['start']);
        $this->assertSame(['at' => '2026-02-07 11:59:59', 'offset' => -5], $phases[0]['end']);
        // Starts keep their time, in UTC-5
        $this->assertSame(['at' => '2026-02-24 12:00:00', 'offset' => -5], $phases[1]['start']);
        $this->assertSame(['at' => '2026-03-11 11:59:59', 'offset' => -5], $phases[1]['end']);
        $this->assertSame('RE-Factor Headhunting', $phases[2]['title']);
        $this->assertSame([[LogLevel::INFO, '1.0.3', '2026-03-11 16:59:59', '2026-03-11 11:59:59']], $this->logSummary());
    }

    public function testEndfieldWeaponEndWithTheWrongYearGetsOneMoreYear(): void
    {
        $phases = $this->normalizer->normalize([
            ['id' => 20015, 'version' => '1.6.2', 'start' => '2026-11-05 12:00:00', 'end' => '2026-01-07 16:59:59', 'rateUps' => ['Lunar Calling']],
        ], 'weapon', IrminsulTimeRules::endfield());

        $this->assertCount(1, $phases);
        $this->assertSame(['at' => '2026-11-05 12:00:00', 'offset' => -5], $phases[0]['start']);
        $this->assertSame(['at' => '2027-01-07 11:59:59', 'offset' => -5], $phases[0]['end']);
        $this->assertSame([
            [LogLevel::INFO, '1.6.2', '2026-01-07 16:59:59', '2026-01-07 11:59:59'],
            [LogLevel::WARNING, '1.6.2', '2026-01-07 11:59:59', '2027-01-07 11:59:59'],
        ], $this->logSummary());
    }

    public function testNteTimesAreKeptAsTheyAre(): void
    {
        $entries = [
            ['id' => 10000, 'version' => '1.0.1', 'start' => '2026-04-29 11:00:00 UTC+8', 'end' => '2026-05-13 05:59:59 UTC+8', 'rateUps' => ['Nanally']],
            ['id' => 10001, 'version' => '1.0.2', 'start' => '2026-05-13 11:00:00 UTC+8', 'end' => '2026-06-03 05:59:59 UTC+8', 'rateUps' => ['Hotori']],
        ];

        $phases = $this->normalizer->normalize($entries, 'character', IrminsulTimeRules::nte());

        $this->assertSame([
            [['at' => '2026-04-29 11:00:00', 'offset' => 8], ['at' => '2026-05-13 05:59:59', 'offset' => 8]],
            [['at' => '2026-05-13 11:00:00', 'offset' => 8], ['at' => '2026-06-03 05:59:59', 'offset' => 8]],
        ], array_map(static fn (array $phase): array => [$phase['start'], $phase['end']], $phases));
        $this->assertSame([], $this->logs);
    }

    public function testZoneSuffixesAreParsed(): void
    {
        $phases = $this->normalizer->normalize([
            ['id' => 1, 'version' => '1.0.1', 'start' => '2026-01-01 10:00:00 UTC+8', 'end' => '2026-01-20 12:00:00 UTC-5', 'rateUps' => []],
            ['id' => 2, 'version' => '1.0.1', 'start' => '2026-01-01 10:00:00UTC', 'end' => ' 2026-01-20 12:00:00 ', 'rateUps' => []],
        ], 'character', IrminsulTimeRules::nte());

        $this->assertSame(['at' => '2026-01-01 10:00:00', 'offset' => 8], $phases[0]['start']);
        $this->assertSame(['at' => '2026-01-20 12:00:00', 'offset' => -5], $phases[0]['end']);
        $this->assertSame(['at' => '2026-01-01 10:00:00', 'offset' => 0], $phases[1]['start']);
        $this->assertSame(['at' => '2026-01-20 12:00:00', 'offset' => null], $phases[1]['end']);
    }

    public function testImplausiblePhasesAreDropped(): void
    {
        $phases = $this->normalizer->normalize([
            ['id' => 1, 'version' => '1.0.1', 'start' => '2026-01-01 10:00:00 UTC+8', 'end' => '2026-05-15 11:59:59', 'rateUps' => ['Too long']],
            ['id' => 2, 'version' => '1.0.2', 'start' => '2026-06-01 12:00:00', 'end' => '2024-06-20 14:59:59', 'rateUps' => ['Still ends before it starts']],
            ['id' => 3, 'version' => '1.1.1', 'start' => '2026-01-01 10:00:00 UTC+8', 'end' => '2026-04-30 11:59:59', 'rateUps' => ['Just under 120 days']],
        ], 'character', IrminsulTimeRules::nte());

        $this->assertSame([3], array_column($phases, 'id'));
        $warnings = array_values(array_filter($this->logs, static fn (array $log): bool => $log['level'] === LogLevel::WARNING && str_contains($log['message'], 'dropped')));
        $this->assertSame(['1.0.1', '1.0.2'], array_map(static fn (array $log): string => $log['context']['version'], $warnings));
    }

    public function testMalformedEntriesAreSkipped(): void
    {
        $valid = ['id' => 10000, 'version' => '1.0.1', 'start' => '2024-07-04 10:00:00 UTC+8', 'end' => '2024-07-24 11:59:59', 'rateUps' => ['Ellen', 42, ' Anton ', '', 'Ellen']];

        $phases = $this->normalizer->normalize([
            'not an entry',
            array_diff_key($valid, ['id' => true]),
            ['id' => '10001'] + $valid,
            ['version' => ''] + $valid,
            ['start' => '2024-02-30 10:00:00 UTC+8'] + $valid,
            ['end' => '2024-07-24'] + $valid,
            ['start' => '2024-07-04 10:00:00 UTC+25'] + $valid,
            ['rateUps' => 'Ellen'] + $valid,
            ['id' => 10002, 'title' => ' '] + $valid,
            $valid,
        ], 'character', IrminsulTimeRules::hoyoverse('12:00:00'));

        $this->assertSame([10002, 10000], array_column($phases, 'id'));
        $this->assertNull($phases[0]['title']);
        $this->assertSame(['Ellen', 'Anton'], $phases[1]['rate_ups']);
        $this->assertCount(8, array_filter($this->logs, static fn (array $log): bool => $log['level'] === LogLevel::WARNING && str_contains($log['message'], 'malformed')));
    }

    public function testToInstantReadsTheOffsetOrTheLocalZone(): void
    {
        $utc = new \DateTimeZone('UTC');

        $this->assertSame('2026-11-04 03:00:00', IrminsulPhaseNormalizer::toInstant(['at' => '2026-11-04 11:00:00', 'offset' => 8])->setTimezone($utc)->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-04 16:59:59', IrminsulPhaseNormalizer::toInstant(['at' => '2026-11-04 11:59:59', 'offset' => -5])->setTimezone($utc)->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-24 17:00:00', IrminsulPhaseNormalizer::toInstant(['at' => '2026-11-24 18:00:00', 'offset' => null], new \DateTimeZone('Europe/Paris'))->setTimezone($utc)->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-24 18:00:00', IrminsulPhaseNormalizer::toInstant(['at' => '2026-11-24 18:00:00', 'offset' => null])->setTimezone($utc)->format('Y-m-d H:i:s'));
        // The offset wins over the local zone
        $this->assertSame('2026-11-04 03:00:00', IrminsulPhaseNormalizer::toInstant(['at' => '2026-11-04 11:00:00', 'offset' => 8], new \DateTimeZone('America/New_York'))->setTimezone($utc)->format('Y-m-d H:i:s'));

        $this->expectException(\InvalidArgumentException::class);
        IrminsulPhaseNormalizer::toInstant(['at' => '2026-11-31 11:00:00', 'offset' => null]);
    }

    public function testThePhaseTwoStartMustBeATimeOfDay(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        IrminsulTimeRules::hoyoverse('18:00');
    }

    /**
     * @return list<array{string, string, string, string}> level, version, from, to of each rewrite or year fix
     */
    private function logSummary(): array
    {
        return array_map(
            static fn (array $log): array => [$log['level'], (string) $log['context']['version'], (string) $log['context']['from'], (string) $log['context']['to']],
            $this->logs,
        );
    }

    public function testWarningsGoToTheCallersLoggerWhenGiven(): void
    {
        $recorder = static fn (): \Psr\Log\AbstractLogger => new class extends \Psr\Log\AbstractLogger {
            /** @var list<array{level: mixed, message: string}> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message];
            }
        };
        $default = $recorder();
        $caller = $recorder();
        $normalizer = new IrminsulPhaseNormalizer($default);
        $malformed = [['id' => 1, 'version' => '1.0.1']];

        $normalizer->normalize($malformed, 'character', IrminsulTimeRules::nte(), $caller);
        $this->assertSame(['irminsul banner entry skipped: malformed'], array_column($caller->records, 'message'));
        $this->assertSame([], $default->records);

        $normalizer->normalize($malformed, 'character', IrminsulTimeRules::nte());
        $this->assertSame(['irminsul banner entry skipped: malformed'], array_column($default->records, 'message'), 'Without a caller logger: the default one');
    }
}
