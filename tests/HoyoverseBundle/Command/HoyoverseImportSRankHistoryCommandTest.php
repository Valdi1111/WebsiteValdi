<?php

namespace App\Tests\HoyoverseBundle\Command;

use App\GamesBundle\Model\Pull\PullImportSummary;
use App\HoyoverseBundle\Command\HoyoverseImportSRankHistoryCommand;
use App\HoyoverseBundle\Entity\HoyoverseGameProfile;
use App\HoyoverseBundle\Message\Profile\ImportSRankHistoryMessage;
use App\HoyoverseBundle\Repository\HoyoverseGameProfileRepository;
use App\HoyoverseBundle\Service\Pull\PullOperationTracker;
use App\HoyoverseBundle\Service\Pull\SRankHistoryImportService;
use App\HoyoverseBundle\Service\ZenlessZoneZeroService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * hoyoverse:import-s-rank-history around a stubbed import service.
 */
class HoyoverseImportSRankHistoryCommandTest extends TestCase
{
    /** @var list<array{dryRun: bool, blockingLock: bool}> */
    private array $imports = [];

    /** @var list<object> */
    private array $dispatched = [];

    private function createTester(bool $supported = true): CommandTester
    {
        $profile = (new HoyoverseGameProfile())->setGameId(ZenlessZoneZeroService::GAME_ID)->setNickname('Proxy');
        (new \ReflectionProperty(HoyoverseGameProfile::class, 'id'))->setValue($profile, 10);
        $repository = $this->createStub(HoyoverseGameProfileRepository::class);
        $repository->method('find')->willReturnCallback(static fn (int $id): ?HoyoverseGameProfile => $id === 10 ? $profile : null);

        $importService = $this->createStub(SRankHistoryImportService::class);
        $importService->method('getSupportedGame')->willReturn($supported ? new ZenlessZoneZeroService(new NullLogger()) : null);
        $importService->method('import')->willReturnCallback(function (HoyoverseGameProfile $profile, ?callable $onProgress = null, bool $dryRun = false, bool $blockingLock = true): PullImportSummary {
            $this->imports[] = ['dryRun' => $dryRun, 'blockingLock' => $blockingLock];

            return new PullImportSummary('s_rank_history', 'Official S-Rank History', 2, 3, 1, ['Standard Channel: not imported: A conflict'], $dryRun, 1, [
                '2' => ['gacha_type' => '2', 'name' => 'Exclusive Channel', 'status' => 'completed', 'imported' => 3, 'updated' => 1, 'matched' => 5, 'error' => null],
                '1' => ['gacha_type' => '1', 'name' => 'Standard Channel', 'status' => 'skipped', 'imported' => 0, 'updated' => 0, 'matched' => 0, 'error' => 'A conflict'],
            ]);
        });

        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $message): Envelope {
            $this->dispatched[] = $message;

            return new Envelope($message);
        });

        return new CommandTester(new HoyoverseImportSRankHistoryCommand(
            $repository,
            $importService,
            new PullOperationTracker(new ArrayAdapter(), $this->createStub(HubInterface::class), 'games.test'),
            $bus,
        ));
    }

    public function testDryRunPrintsEveryBanner(): void
    {
        $tester = $this->createTester();

        $this->assertSame(Command::SUCCESS, $tester->execute(['profileId' => '10', '--dry-run' => true]));

        $this->assertSame([['dryRun' => true, 'blockingLock' => true]], $this->imports);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('[DRY RUN]', $display);
        $this->assertStringContainsString('Would create', $display);
        $this->assertMatchesRegularExpression('/Exclusive Channel\s+completed\s+5\s+3\s+1/', $display);
        $this->assertMatchesRegularExpression('/Standard Channel\s+skipped\s+0\s+0\s+0\s+A conflict/', $display);
        $this->assertStringContainsString('nothing was written', $display);
    }

    public function testAsyncQueuesTheMessage(): void
    {
        $tester = $this->createTester();

        $this->assertSame(Command::SUCCESS, $tester->execute(['profileId' => '10', '--async' => true]));

        $this->assertSame([], $this->imports);
        $this->assertCount(1, $this->dispatched);
        $this->assertInstanceOf(ImportSRankHistoryMessage::class, $this->dispatched[0]);
        $this->assertSame(Command::FAILURE, $this->createTester()->execute(['profileId' => '10', '--async' => true, '--dry-run' => true]));
    }

    public function testRefusesAnUnknownOrUnsupportedProfile(): void
    {
        $this->assertSame(Command::FAILURE, $this->createTester()->execute(['profileId' => '11']));
        $this->assertSame(Command::FAILURE, $this->createTester(supported: false)->execute(['profileId' => '10']));
        $this->assertSame([], $this->imports);
    }
}
