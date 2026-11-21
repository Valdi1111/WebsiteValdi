<?php

namespace App\Tests\PerfectworldBundle\Service;

use App\PerfectworldBundle\Entity\PerfectworldGameProfile;
use App\PerfectworldBundle\Entity\PerfectworldPull;
use App\PerfectworldBundle\Model\Pull\ImportedPullRecord;
use App\PerfectworldBundle\Service\Catalog\NteCatalog;
use App\PerfectworldBundle\Service\NevernessToEvernessService;
use App\PerfectworldBundle\Service\Pull\Importer\NteHistoryExportImporter;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

class NteHistoryExportImporterTest extends TestCase
{
    private const string UID = '100000000001';

    /** @var list<string> */
    private array $files = [];
    private int $second = 0;

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
    }

    private function createImporter(?array $catalogItems = null): NteHistoryExportImporter
    {
        $catalog = new NteCatalog(new ArrayAdapter());
        if ($catalogItems !== null) {
            $catalog->save(['sha' => 'abc', 'version' => '1.5.4 (Global)', 'built_at' => null, 'items' => $catalogItems, 'avatars' => [], 'pools' => []]);
        }

        return new NteHistoryExportImporter(new NevernessToEvernessService(new NullLogger()), $catalog);
    }

    private static function profile(string $uid = self::UID): PerfectworldGameProfile
    {
        return (new PerfectworldGameProfile())->setGameUid($uid);
    }

    /**
     * Board record; $type is "dice", "points_gift" or "chase_reward".
     */
    private static function boardRecord(string $type, string $rewardType = 'arc', ?string $rank = 'B', string $rewardId = 'fork_vine', int $roll = 3): array
    {
        return [
            'uid' => md5(uniqid('', true)),
            'pool_group_id' => 'x',
            'roll_result' => match ($type) {
                'points_gift' => 0,
                'chase_reward' => -4,
                default => $roll,
            },
            'result_type' => $type,
            'reward_type' => $rewardType,
            'reward_id' => $rewardId,
            'reward_name' => $rewardId === 'fork_vine' ? 'Be Happy' : ucfirst($rewardId),
            'reward_rank' => $rank,
            'quantity' => 1,
        ];
    }

    private static function gift(): array
    {
        return self::boardRecord('points_gift', 'character', 'A', '1033');
    }

    /**
     * A group of records sharing one second, given oldest first; returned newest first like the exporter.
     *
     * @param list<array<string, mixed>> $oldestFirst
     * @return list<array<string, mixed>>
     */
    private function group(array $oldestFirst): array
    {
        $this->second++;
        $timestamp = sprintf('2026-06-01 10:%02d:%02d', intdiv($this->second, 60), $this->second % 60);

        return array_map(
            static fn (array $record): array => ['timestamp' => $timestamp] + $record,
            array_reverse($oldestFirst),
        );
    }

    /**
     * A 10-roll of a fixed exporter: 10 rolls with the gift right after the roll completing a tenth.
     *
     * @return list<array<string, mixed>>
     */
    private function tenRoll(int $rollsBefore, ?callable $mutate = null): array
    {
        $records = [];
        for ($i = 1; $i <= 10; $i++) {
            $records[] = self::boardRecord('dice');
            if (($rollsBefore + $i) % 10 === 0) {
                $records[] = self::gift();
            }
        }

        return $this->group($mutate !== null ? $mutate($records) : $records);
    }

    /**
     * @param list<list<array<string, mixed>>> $groupsOldestFirst
     */
    private function writeExport(array $groupsOldestFirst, string $version = '0.5.0', string $banner = NevernessToEvernessService::BANNER_LIMITED, array $overrides = []): string
    {
        $records = array_merge(...array_reverse($groupsOldestFirst));
        $pages = (int) max(1, ceil(count($records) / 5));
        $data = array_replace_recursive([
            'format' => 'nte-history-export',
            'format_version' => 1,
            'game' => 'Neverness to Everness',
            'exporter' => ['name' => 'nte-history-exporter', 'version' => $version],
            'banner' => ['id' => $banner, 'name' => 'Board', 'system' => 'Monopoly', 'shared_pity' => true],
            'scan' => [
                'mode' => 'stable_only',
                'boundary_policy' => 'export_ordinal_stable_groups',
                'decoded_records' => count($records),
                'exported_records' => count($records),
                'skipped_records' => 0,
                'warnings' => [],
                'pages_seen' => range(1, $pages),
            ],
            'user_uid' => self::UID,
            'server_id' => '23003',
            'account_region' => 'EU',
        ], $overrides);
        $data['records'] = $records;

        $file = tempnam(sys_get_temp_dir(), 'nte_export_test_');
        file_put_contents($file, json_encode($data));
        $this->files[] = $file;

        return $file;
    }

    /**
     * @param list<ImportedPullRecord> $records
     * @return list<string> Kinds oldest first, compact: R = roll, G = gift, S = Slumberland
     */
    private static function kindsOldestFirst(array $records): array
    {
        $map = [PerfectworldPull::KIND_ROLL => 'R', PerfectworldPull::KIND_POINTS_GIFT => 'G', PerfectworldPull::KIND_SLUMBERLAND => 'S', PerfectworldPull::KIND_DRAW => 'D'];

        return array_map(static fn (ImportedPullRecord $record): string => $map[$record->kind], array_reverse($records));
    }

    /**
     * Fixed-exporter history: 9 single rolls, a roll + gift, 3 single rolls, a 10-roll with a
     * Slumberland chase, then a single roll.
     *
     * @return list<list<array<string, mixed>>>
     */
    private function fixedHistory(): array
    {
        $groups = [];
        for ($i = 0; $i < 9; $i++) {
            $groups[] = $this->group([self::boardRecord('dice')]);
        }
        $groups[] = $this->group([self::boardRecord('dice'), self::gift()]); // roll 10
        for ($i = 0; $i < 3; $i++) {
            $groups[] = $this->group([self::boardRecord('dice')]); // rolls 11-13
        }
        $groups[] = $this->tenRoll(13, static function (array $records): array {
            $records[] = self::boardRecord('chase_reward', 'item', 'S', 'Dice_ticket_01');

            return $records;
        }); // rolls 14-23, gift after roll 20
        $groups[] = $this->group([self::boardRecord('dice')]); // roll 24

        return $groups;
    }

    public function testInspectReadsTheHeader(): void
    {
        $file = $this->writeExport($this->fixedHistory());

        $inspection = $this->createImporter()->inspect($file);

        $this->assertSame(self::UID, $inspection->gameUid);
        $this->assertSame('0.5.0', $inspection->exporterVersion);
        $this->assertSame([NevernessToEvernessService::BANNER_LIMITED => ['name' => 'Scarborough Fair (Limited Boards)', 'count' => 27]], $inspection->banners);
    }

    public function testFixedExportKeepsItsLabelsAndPositions(): void
    {
        $records = $this->createImporter()->parse(self::profile(), $this->writeExport($this->fixedHistory()));

        $this->assertSame(
            'RRRRRRRRR' . 'RG' . 'RRR' . 'RRRRRRRGRRRS' . 'R',
            implode('', self::kindsOldestFirst($records)),
        );

        // The 10-roll group, newest first: chase (pos 0), rolls 23..21, gift (pos 4), roll 20...
        $tenRoll = array_values(array_filter($records, static fn (ImportedPullRecord $record): bool => $record->pulledAt === $records[1]->pulledAt));
        $this->assertSame(range(0, 11), array_map(static fn (ImportedPullRecord $record): int => $record->groupPosition, $tenRoll));
        $this->assertSame(PerfectworldPull::KIND_SLUMBERLAND, $tenRoll[0]->kind);
        $this->assertNull($tenRoll[0]->rollResult);
        $this->assertSame(3, $tenRoll[1]->rollResult);
        $this->assertSame('0.5.0', $records[0]->exporterVersion);
    }

    public function testOldExportLabelsAreRepairedFromTheGiftRule(): void
    {
        $groups = $this->fixedHistory();
        // Exporter 0.3.0: some rolls come out as "points_gift" with roll 0
        $groups[2][0]['result_type'] = 'points_gift';
        $groups[2][0]['roll_result'] = 0;
        $groups[13][3]['result_type'] = 'points_gift';
        $groups[13][3]['roll_result'] = 0;
        $groups[13][3]['reward_type'] = 'character';
        $groups[13][3]['reward_rank'] = 'S';

        $records = $this->createImporter()->parse(self::profile(), $this->writeExport($groups, '0.3.0'));

        $this->assertSame('RRRRRRRRR' . 'RG' . 'RRR' . 'RRRRRRRGRRRS' . 'R', implode('', self::kindsOldestFirst($records)));
        $relabelled = array_values(array_filter($records, static fn (ImportedPullRecord $record): bool => $record->kind === PerfectworldPull::KIND_ROLL && $record->rollResult === null));
        $this->assertCount(2, $relabelled, 'Relabelled rolls have no dice value');
    }

    public function testTruncatedOldExportIsRepairedWithTheRightPhase(): void
    {
        $groups = array_slice($this->fixedHistory(), 5); // history window cut: 5 single rolls missing
        $groups[1][0]['result_type'] = 'points_gift';

        $records = $this->createImporter()->parse(self::profile(), $this->writeExport($groups, '0.3.0'));

        $this->assertSame('RRRR' . 'RG' . 'RRR' . 'RRRRRRRGRRRS' . 'R', implode('', self::kindsOldestFirst($records)));
    }

    public function testFixedExportKeepsACutOldestSecond(): void
    {
        // Scan stopped inside a 10-roll: only its 4 most recent records (chase + 3 rolls) were exported
        $groups = array_slice($this->fixedHistory(), 13);
        $groups[0] = array_slice($groups[0], 0, 4);

        $records = $this->createImporter()->parse(self::profile(), $this->writeExport($groups));

        $this->assertSame('RRRSR', implode('', self::kindsOldestFirst($records)));
        // Newest first in the file: the kept records keep positions 0..3 of their second
        $this->assertSame([0, 1, 2, 3], array_map(static fn (ImportedPullRecord $record): int => $record->groupPosition, array_slice($records, 1)));
    }

    public function testOldExportLabelsACutOldestSecondFromTheFollowingPhase(): void
    {
        // History window cut a "roll + gift" second: only the gift is left, which looks like a single record
        $groups = array_slice($this->fixedHistory(), 9);
        $groups[0] = array_slice($groups[0], 0, 1);

        $records = $this->createImporter()->parse(self::profile(), $this->writeExport($groups, '0.3.0'));

        $this->assertSame('G' . 'RRR' . 'RRRRRRRGRRRS' . 'R', implode('', self::kindsOldestFirst($records)));
    }

    public function testOldExportWithoutAConsistentPhaseIsRejected(): void
    {
        // A 10-roll whose gift record is not labelled as such anywhere: no phase can place it
        $groups = [
            $this->group([self::boardRecord('dice')]),
            $this->group(array_fill(0, 11, self::boardRecord('dice'))),
            $this->group([self::boardRecord('dice')]),
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('could not be repaired');
        $this->createImporter()->parse(self::profile(), $this->writeExport($groups, '0.3.0'));
    }

    public function testFixedExportWithInconsistentLabelsIsRejected(): void
    {
        $groups = $this->fixedHistory();
        $groups[13][5]['result_type'] = 'points_gift'; // a second gift in the 10-roll

        $this->expectException(\InvalidArgumentException::class);
        $this->createImporter()->parse(self::profile(), $this->writeExport($groups));
    }

    public function testUnexpectedGroupSizeIsRejected(): void
    {
        // Only the oldest second may be incomplete: a 3-record second in the middle is corrupted
        $groups = [
            $this->group([self::boardRecord('dice')]),
            $this->group([self::boardRecord('dice'), self::boardRecord('dice'), self::boardRecord('dice')]),
            $this->group([self::boardRecord('dice')]),
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unexpected group of 3 records');
        $this->createImporter()->parse(self::profile(), $this->writeExport($groups));
    }

    public function testCatalogNamesAndRaritiesWinOverTheFile(): void
    {
        $groups = [$this->group([self::boardRecord('dice', 'character', null, '1042', 6)])];
        $groups[0][0]['reward_name'] = '';

        $catalog = ['1042' => ['id' => '1042', 'name' => 'Blackbird', 'rank' => 'S', 'rarity' => 5, 'type' => 'character', 'icon_path' => 'UI_Icon/AvatarImage/200/player_heiyu.png']];
        $records = $this->createImporter($catalog)->parse(self::profile(), $this->writeExport($groups));

        $this->assertSame('Blackbird', $records[0]->name);
        $this->assertSame(5, $records[0]->rarity);
        $this->assertNull($records[0]->warning);
    }

    public function testUnknownItemsFallBackToTheFileOrAPlaceholder(): void
    {
        $groups = [$this->group([self::boardRecord('dice', 'character', '', '1999', 2)])];
        $groups[0][0]['reward_name'] = '';

        $records = $this->createImporter()->parse(self::profile(), $this->writeExport($groups));

        $this->assertSame('Unknown #1999', $records[0]->name);
        $this->assertNull($records[0]->rarity);
        $this->assertStringContainsString('does not count for pity', (string) $records[0]->warning);
    }

    public function testArcRecordsAreDrawsWithDefaultQuantity(): void
    {
        $records = [];
        for ($i = 0; $i < 10; $i++) {
            $records[] = ['uid' => md5((string) $i), 'reward_type' => 'arc', 'reward_id' => 'fork_vine', 'reward_name' => 'Be Happy', 'reward_rank' => 'B', 'source_type' => 'miracle_box'];
        }
        $file = $this->writeExport([$this->group($records)], '0.5.0', NevernessToEvernessService::BANNER_ARC);

        $parsed = $this->createImporter()->parse(self::profile(), $file);

        $this->assertCount(10, $parsed);
        $this->assertSame(PerfectworldPull::KIND_DRAW, $parsed[0]->kind);
        $this->assertSame(1, $parsed[0]->quantity);
        $this->assertSame(NevernessToEvernessService::BANNER_ARC, $parsed[0]->bannerKey);
    }

    public function testExportOfAnotherCharacterIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('another character');
        $this->createImporter()->parse(self::profile('999999999999'), $this->writeExport($this->fixedHistory()));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidHeaderProvider(): iterable
    {
        yield 'not an nte-exporter file' => [['format' => 'other'], 'not an nte-exporter file'];
        yield 'mystery box' => [['banner' => ['id' => 'Gashapon_MysteryBox']], 'Mystery Box'];
        yield 'unknown banner' => [['banner' => ['id' => 'Lottery_Unknown']], 'Unsupported banner'];
        yield 'scan not from page 1' => [['scan' => ['pages_seen' => [2, 3, 4, 5, 6, 7]]], 'start at page 1'];
        yield 'skipped records' => [['scan' => ['skipped_records' => 2]], 'skipped'];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidHeaderProvider')]
    public function testInvalidHeadersAreRejected(array $overrides, string $message): void
    {
        $file = $this->writeExport($this->fixedHistory(), overrides: $overrides);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        $this->createImporter()->inspect($file);
    }
}
