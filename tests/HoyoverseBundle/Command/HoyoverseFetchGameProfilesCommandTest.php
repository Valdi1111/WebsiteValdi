<?php

namespace App\Tests\HoyoverseBundle\Command;

use App\HoyoverseBundle\Command\HoyoverseFetchGameProfilesCommand;
use App\HoyoverseBundle\Entity\HoyoverseAccount;
use App\HoyoverseBundle\Entity\HoyoverseGameProfile;
use App\HoyoverseBundle\Repository\HoyoverseAccountRepository;
use App\HoyoverseBundle\Service\HoyolabManagerService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * hoyoverse:fetch-game-profiles around a stubbed HoYoLAB manager (the shared flow is in AbstractFetchGameProfilesCommandTest).
 */
class HoyoverseFetchGameProfilesCommandTest extends TestCase
{
    public function testFetchesFromTheGameRecordsAndPrintsTheAccountProfiles(): void
    {
        $account = new HoyoverseAccount();
        (new \ReflectionProperty(HoyoverseAccount::class, 'id'))->setValue($account, 4);

        $repository = $this->createStub(HoyoverseAccountRepository::class);
        $repository->method('find')->willReturnCallback(static fn (int $id): ?HoyoverseAccount => $id === 4 ? $account : null);

        $updated = [];
        $manager = $this->createStub(HoyolabManagerService::class);
        $manager->method('updateCachedGameProfilesByGameRecords')->willReturnCallback(static function (HoyoverseAccount $account) use (&$updated): HoyoverseAccount {
            $updated[] = $account;

            return $account->addGameProfile(
                (new HoyoverseGameProfile())->setGameName('Genshin Impact')->setGameUid('700000001')->setNickname('Traveler')->setLevel(60)->setParsedRegion('europe')->setParsedTimezone('Etc/GMT+1')
            );
        });

        $tester = new CommandTester(new HoyoverseFetchGameProfilesCommand($repository, $manager));
        $exitCode = $tester->execute(['accountId' => '4']);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame([$account], $updated);
        $display = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('Fetching the game profiles of 1 HoYoverse account(s)', $display);
        self::assertStringContainsString('Genshin Impact 700000001 Traveler 60 europe Etc/GMT+1', $display);
    }
}
