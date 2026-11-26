<?php

namespace App\Tests\PerfectworldBundle\Catalog;

use App\PerfectworldBundle\Command\NteCatalogUpdateCommand;
use App\PerfectworldBundle\Entity\PerfectworldPull;
use App\PerfectworldBundle\Repository\PerfectworldPullRepository;
use App\PerfectworldBundle\Service\Catalog\NteCatalog;
use App\PerfectworldBundle\Service\Catalog\NteCatalogBackfiller;
use App\PerfectworldBundle\Service\Catalog\NteCatalogBuilder;
use App\PerfectworldBundle\Service\Catalog\NteCatalogUpdater;
use Doctrine\ORM\EntityManagerInterface;
use App\PerfectworldBundle\Service\Pull\ManualPullService;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class NteCatalogUpdateCommandTest extends TestCase
{
    public function testBuildsSavesAndBackfills(): void
    {
        $pool = new ArrayAdapter();
        $catalog = new NteCatalog($pool);

        $pull = (new PerfectworldPull())
            ->setRewardType('arc')
            ->setRewardId('fork_twinbirds')
            ->setName('Unknown #fork_twinbirds')
            ->setRarity(null);

        $repository = $this->createStub(PerfectworldPullRepository::class);
        $repository->method('findBy')->willReturn([$pull]);

        $tester = new CommandTester($this->createCommand(new NteAssetsFixture(), $catalog, $repository));
        $exitCode = $tester->execute(['--ref' => 'main']);

        $this->assertSame(Command::SUCCESS, $exitCode, $tester->getDisplay());
        $display = $tester->getDisplay();
        $this->assertStringContainsString(NteAssetsFixture::SHA, $display);
        $this->assertStringContainsString('1.5.4', $display);
        $this->assertMatchesRegularExpression('/Backfilled pulls\s+1/', $display);
        $this->assertMatchesRegularExpression('/arc\s+2/', $display);
        $this->assertMatchesRegularExpression('/Banners\s+1/', $display);

        // Saved in the pool: a fresh reader sees it
        $reader = new NteCatalog($pool);
        $this->assertSame(NteAssetsFixture::SHA, $reader->getSha());
        $this->assertSame('Crime and Punishment', $pull->getName());
        $this->assertSame(5, $pull->getRarity());
    }

    public function testAbortedBuildKeepsPreviousCatalogAndFails(): void
    {
        $catalog = new NteCatalog(new ArrayAdapter());
        $catalog->save([
            'sha' => 'ffffffffffffffffffffffffffffffffffffffff',
            'version' => '1.5.0',
            'items' => ['1042' => ['id' => '1042', 'name' => 'Blackbird', 'rank' => 'S', 'type' => 'character', 'icon_path' => null]],
        ]);

        $repository = $this->createMock(PerfectworldPullRepository::class);
        $repository->expects($this->never())->method('findBy');

        $fixture = new NteAssetsFixture(['README.md' => "> Version: 1.6.0 (CN)\n"]);
        $tester = new CommandTester($this->createCommand($fixture, $catalog, $repository));

        $this->assertSame(Command::FAILURE, $tester->execute([]));
        $this->assertStringContainsString('previous catalog kept', $tester->getDisplay());
        $this->assertSame('ffffffffffffffffffffffffffffffffffffffff', $catalog->getSha());
    }

    private function createCommand(NteAssetsFixture $fixture, NteCatalog $catalog, PerfectworldPullRepository $repository): NteCatalogUpdateCommand
    {
        return new NteCatalogUpdateCommand(new NteCatalogUpdater(
            new NteCatalogBuilder($fixture->client(), $catalog, new NullLogger()),
            $catalog,
            new NteCatalogBackfiller($catalog, $repository, $this->createStub(EntityManagerInterface::class), $this->createStub(ManualPullService::class), new LockFactory(new InMemoryStore()), new ServiceLocator([]), new NullLogger()),
            new NullLogger(),
        ));
    }
}
