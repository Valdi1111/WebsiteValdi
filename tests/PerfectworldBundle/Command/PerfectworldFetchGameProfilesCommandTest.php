<?php

namespace App\Tests\PerfectworldBundle\Command;

use App\PerfectworldBundle\Command\PerfectworldFetchGameProfilesCommand;
use App\PerfectworldBundle\Entity\PerfectworldAccount;
use App\PerfectworldBundle\Entity\PerfectworldGameProfile;
use App\PerfectworldBundle\Exception\PerfectworldAuthException;
use App\PerfectworldBundle\Repository\PerfectworldAccountRepository;
use App\PerfectworldBundle\Service\PerfectworldProfileSyncService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * perfectworld:fetch-game-profiles around a stubbed profile sync (the shared flow is in AbstractFetchGameProfilesCommandTest).
 */
class PerfectworldFetchGameProfilesCommandTest extends TestCase
{
    /**
     * @param list<PerfectworldAccount> $accounts
     */
    private function createTester(array $accounts, \Closure $sync): CommandTester
    {
        $repository = $this->createStub(PerfectworldAccountRepository::class);
        $repository->method('findAll')->willReturn($accounts);

        $syncService = $this->createStub(PerfectworldProfileSyncService::class);
        $syncService->method('syncProfiles')->willReturnCallback($sync);

        return new CommandTester(new PerfectworldFetchGameProfilesCommand($repository, $syncService));
    }

    private static function createAccount(int $id): PerfectworldAccount
    {
        $account = new PerfectworldAccount();
        (new \ReflectionProperty(PerfectworldAccount::class, 'id'))->setValue($account, $id);

        return $account;
    }

    public function testSyncsEveryAccountAndReportsAnExpiredToken(): void
    {
        $synced = [];
        $tester = $this->createTester(
            [self::createAccount(1), self::createAccount(2)],
            static function (PerfectworldAccount $account) use (&$synced): void {
                $synced[] = $account->getId();
                if ($account->getId() === 1) {
                    throw new PerfectworldAuthException();
                }
                $account->addGameProfile(
                    (new PerfectworldGameProfile())->setGameName('Neverness to Everness')->setGameUid('5550001')->setNickname('Hethereau')->setLevel(30)->setParsedRegion('asia')->setParsedTimezone('Asia/Shanghai')
                );
            },
        );

        $exitCode = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertSame([1, 2], $synced);
        $display = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('Fetching the game profiles of 2 Perfect World account(s)', $display);
        self::assertStringContainsString('Failed to fetch the game profiles of account #1: Login expired, please log in again.', $display);
        self::assertStringContainsString('Neverness to Everness 5550001 Hethereau 30 asia Asia/Shanghai', $display);
        self::assertStringContainsString('The fetch failed for 1 of 2 account(s).', $display);
    }
}
