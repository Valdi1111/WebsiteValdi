<?php

namespace App\Tests\HoyoverseBundle\MessageHandler;

use App\GamesBundle\Exception\PullProfileLockedException;
use App\GamesBundle\Model\Pull\PullImportProgress;
use App\GamesBundle\Model\Pull\PullImportSummary;
use App\HoyoverseBundle\Entity\HoyoverseGameProfile;
use App\HoyoverseBundle\Exception\RetrieveSRankHistoryException;
use App\HoyoverseBundle\Message\Profile\ImportSRankHistoryMessage;
use App\HoyoverseBundle\MessageHandler\Profile\ImportSRankHistoryHandler;
use App\HoyoverseBundle\Repository\HoyoverseGameProfileRepository;
use App\HoyoverseBundle\Service\Pull\PullOperationTracker;
use App\HoyoverseBundle\Service\Pull\SRankHistoryImportService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

class ImportSRankHistoryHandlerTest extends TestCase
{
    private PullOperationTracker $tracker;

    /** @var list<Envelope> */
    private array $dispatched = [];

    protected function setUp(): void
    {
        $this->tracker = new PullOperationTracker(new ArrayAdapter(), $this->createStub(HubInterface::class), 'games.example.test');
    }

    /**
     * @param \Closure(HoyoverseGameProfile, ?callable): PullImportSummary $import
     */
    private function createHandler(\Closure $import, bool $profileExists = true): ImportSRankHistoryHandler
    {
        $profile = (new HoyoverseGameProfile())->setGameId(8);
        $repository = $this->createStub(HoyoverseGameProfileRepository::class);
        $repository->method('find')->willReturn($profileExists ? $profile : null);

        $importService = $this->createStub(SRankHistoryImportService::class);
        $importService->method('import')->willReturnCallback(
            static fn (HoyoverseGameProfile $profile, ?callable $onProgress = null): PullImportSummary => $import($profile, $onProgress),
        );

        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $message, array $stamps = []): Envelope {
            return $this->dispatched[] = new Envelope($message, $stamps);
        });

        return new ImportSRankHistoryHandler($repository, $importService, $this->tracker, $bus, new NullLogger());
    }

    private function startOperation(): string
    {
        return (string) $this->tracker->startSRankHistoryImport(10, 1, ['2' => 'Exclusive Channel'])['id'];
    }

    public function testRunsTheImportAndCompletesTheOperation(): void
    {
        $operationId = $this->startOperation();
        $handler = $this->createHandler(function (HoyoverseGameProfile $profile, ?callable $onProgress) use ($operationId): PullImportSummary {
            $onProgress(new PullImportProgress('fetching', 1, 0, 0, 0));
            $this->assertSame('fetching', $this->tracker->getOperation($operationId)['phase']);

            return new PullImportSummary('s_rank_history', 'Official S-Rank History', 1, 2, 0, ['A warning'], false, 1, [
                '2' => ['gacha_type' => '2', 'name' => 'Exclusive Channel', 'status' => 'completed', 'imported' => 2, 'updated' => 1, 'error' => null],
            ]);
        });

        $handler(new ImportSRankHistoryMessage($operationId, 10));

        $operation = $this->tracker->getOperation($operationId);
        $this->assertSame(['completed', 2, 1, ['A warning']], [$operation['status'], $operation['imported'], $operation['updated'], $operation['warnings']]);
        $this->assertSame('completed', $operation['banners']['2']['status']);
        $this->assertSame([], $this->dispatched);
    }

    public function testALockedProfileQueuesTheMessageAgainThenGivesUp(): void
    {
        $operationId = $this->startOperation();
        $handler = $this->createHandler(static fn (): never => throw new PullProfileLockedException('busy'));

        $handler(new ImportSRankHistoryMessage($operationId, 10, 3));

        $this->assertCount(1, $this->dispatched);
        $message = $this->dispatched[0]->getMessage();
        $this->assertInstanceOf(ImportSRankHistoryMessage::class, $message);
        $this->assertSame([$operationId, 10, 4], [$message->getOperationId(), $message->getGameProfileId(), $message->getLockAttempt()]);
        $this->assertSame(3000, $this->dispatched[0]->last(DelayStamp::class)?->getDelay());
        $this->assertSame('queued', $this->tracker->getOperation($operationId)['status']);

        $handler(new ImportSRankHistoryMessage($operationId, 10, 20));
        $this->assertCount(1, $this->dispatched, 'No more attempts');
        $this->assertSame('failed', $this->tracker->getOperation($operationId)['status']);
    }

    public function testAFailureFailsTheOperation(): void
    {
        $operationId = $this->startOperation();
        $this->createHandler(static fn (): never => throw new RetrieveSRankHistoryException('API returned error retcode [-100]: Please login'))
            (new ImportSRankHistoryMessage($operationId, 10));

        $operation = $this->tracker->getOperation($operationId);
        $this->assertSame(['failed', 'API returned error retcode [-100]: Please login'], [$operation['status'], $operation['error']]);
        $this->assertSame('failed', $operation['banners']['2']['status']);

        $missing = $this->startOperation();
        $this->createHandler(static fn (): never => throw new \LogicException('not called'), profileExists: false)(new ImportSRankHistoryMessage($missing, 10));
        $this->assertSame('Game profile #10 not found.', $this->tracker->getOperation($missing)['error']);
    }
}
