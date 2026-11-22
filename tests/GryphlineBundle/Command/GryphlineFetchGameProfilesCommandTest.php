<?php

namespace App\Tests\GryphlineBundle\Command;

use App\GryphlineBundle\Command\GryphlineFetchGameProfilesCommand;
use App\GryphlineBundle\Entity\GryphlineAccount;
use App\GryphlineBundle\Entity\GryphlineGameProfile;
use App\GryphlineBundle\Repository\GryphlineAccountRepository;
use App\GryphlineBundle\Service\SkportManagerService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * gryphline:fetch-game-profiles around a stubbed SKPort manager (the shared flow is in AbstractFetchGameProfilesCommandTest).
 */
class GryphlineFetchGameProfilesCommandTest extends TestCase
{
    public function testFetchesTheBindingsAndPrintsTheSkportColumns(): void
    {
        $account = new GryphlineAccount();
        (new \ReflectionProperty(GryphlineAccount::class, 'id'))->setValue($account, 3);

        $repository = $this->createStub(GryphlineAccountRepository::class);
        $repository->method('find')->willReturnCallback(static fn (int $id): ?GryphlineAccount => $id === 3 ? $account : null);

        $updated = [];
        $manager = $this->createStub(SkportManagerService::class);
        $manager->method('updateCachedGameProfiles')->willReturnCallback(static function (GryphlineAccount $account) use (&$updated): array {
            $updated[] = $account;
            $profile = (new GryphlineGameProfile())
                ->setGameId(3)
                ->setGameName('Arknights: Endfield')
                ->setGameUid('1234567')
                ->setServerId('2')
                ->setNickname('Endmin')
                ->setLevel(40)
                ->setRegionName('Asia')
                ->setParsedRegion('asia')
                ->setParsedTimezone('Asia/Shanghai');
            $account->addGameProfile($profile);

            return [$profile];
        });

        $tester = new CommandTester(new GryphlineFetchGameProfilesCommand($repository, $manager));
        $exitCode = $tester->execute(['accountId' => '3']);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame([$account], $updated);
        $display = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('Fetching the game profiles of 1 Gryphline account(s)', $display);
        self::assertStringContainsString('Region Timezone GameId SkGameRole Server', $display);
        self::assertStringContainsString('Arknights: Endfield 1234567 Endmin 40 asia Asia/Shanghai 3 3_1234567_2 Asia', $display);
    }
}
