<?php

namespace App\Tests\GryphlineBundle\Service\Catalog;

use App\GryphlineBundle\Command\EndfieldCatalogUpdateCommand;
use App\GryphlineBundle\Service\Catalog\EndfieldCatalog;
use App\GryphlineBundle\Service\Catalog\EndfieldCatalogBuilder;
use App\GryphlineBundle\Service\Catalog\EndfieldCatalogUpdater;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class EndfieldCatalogUpdateCommandTest extends TestCase
{
    public function testBuildsSavesAndPrintsTheSummary(): void
    {
        $pool = new ArrayAdapter();
        $tester = new CommandTester($this->createCommand(new AkeDataFixture(), new EndfieldCatalog($pool)));

        $this->assertSame(Command::SUCCESS, $tester->execute([]), $tester->getDisplay());

        $display = $tester->getDisplay();
        $this->assertStringContainsString(AkeDataFixture::LATEST, $display);
        $this->assertMatchesRegularExpression('/Items\s+6 \(6 new\)/', $display);
        $this->assertMatchesRegularExpression('/Operators\s+4/', $display);
        $this->assertMatchesRegularExpression('/Weapons\s+2/', $display);
        $this->assertMatchesRegularExpression('/6★\s+4 \(3 operators, 1 weapons\)/', $display);

        $this->assertSame(AkeDataFixture::LATEST, new EndfieldCatalog($pool)->getVersion());
    }

    public function testManifestVersionOption(): void
    {
        $fixture = new AkeDataFixture();
        $catalog = new EndfieldCatalog(new ArrayAdapter());
        $tester = new CommandTester($this->createCommand($fixture, $catalog));

        $this->assertSame(Command::SUCCESS, $tester->execute(['--manifest-version' => AkeDataFixture::OLDER]), $tester->getDisplay());
        $this->assertContains(AkeDataFixture::OLDER_PATH . '/ItemTable.json', $fixture->requestedPaths);
        $this->assertSame(AkeDataFixture::OLDER, $catalog->getVersion());
        $this->assertMatchesRegularExpression('/Operators\s+1/', $tester->getDisplay());
    }

    public function testAbortedBuildKeepsPreviousCatalogAndFails(): void
    {
        $catalog = new EndfieldCatalog(new ArrayAdapter());
        $catalog->save([
            'version' => AkeDataFixture::OLDER,
            'built_at' => '2026-11-16T06:00:00+01:00',
            'items' => ['chr_0005_chen' => ['id' => 'chr_0005_chen', 'name' => 'Chen Qianyu', 'rarity' => 5, 'type' => 'char']],
        ]);

        $tester = new CommandTester($this->createCommand(new AkeDataFixture(), $catalog));

        $this->assertSame(Command::FAILURE, $tester->execute(['--manifest-version' => '9.9.9@1-1']));
        $this->assertStringContainsString('previous catalog kept', $tester->getDisplay());
        $this->assertSame(AkeDataFixture::OLDER, $catalog->getVersion());
    }

    private function createCommand(AkeDataFixture $fixture, EndfieldCatalog $catalog): EndfieldCatalogUpdateCommand
    {
        return new EndfieldCatalogUpdateCommand(new EndfieldCatalogUpdater(
            new EndfieldCatalogBuilder($fixture->client(), new NullLogger()),
            $catalog,
            new NullLogger(),
        ));
    }
}
