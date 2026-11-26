<?php

namespace App\Tests\HoyoverseBundle\Service\Catalog;

use App\HoyoverseBundle\Command\GiCatalogUpdateCommand;
use App\HoyoverseBundle\Command\HsrCatalogUpdateCommand;
use App\HoyoverseBundle\Command\ZzzCatalogUpdateCommand;
use App\HoyoverseBundle\Service\Catalog\GiCatalog;
use App\HoyoverseBundle\Service\Catalog\GiCatalogBuilder;
use App\HoyoverseBundle\Service\Catalog\GiCatalogUpdater;
use App\HoyoverseBundle\Service\Catalog\HsrCatalog;
use App\HoyoverseBundle\Service\Catalog\HsrCatalogBuilder;
use App\HoyoverseBundle\Service\Catalog\HsrCatalogUpdater;
use App\HoyoverseBundle\Service\Catalog\ZzzCatalog;
use App\HoyoverseBundle\Service\Catalog\ZzzCatalogBuilder;
use App\HoyoverseBundle\Service\Catalog\ZzzCatalogUpdater;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class IrminsulCatalogUpdateCommandTest extends TestCase
{
    public function testBuildsAndSavesTheCatalog(): void
    {
        $pool = new ArrayAdapter();
        $catalog = new GiCatalog($pool);
        $fixture = new IrminsulFixture();
        $command = new GiCatalogUpdateCommand(
            new GiCatalogUpdater(new GiCatalogBuilder($fixture->irminsulClient(), IrminsulFixture::normalizer(), new NullLogger()), $catalog, new NullLogger()),
            $catalog,
        );
        $this->assertSame('hoyoverse:gi-catalog:update', $command->getName());

        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertSame(Command::SUCCESS, $exitCode, $display);
        $this->assertStringContainsString('Genshin Impact catalog updated', $display);
        $this->assertStringContainsString((string) $catalog->getFetchedAt(), $display);
        $this->assertMatchesRegularExpression('/Items\s+16/', $display);
        $this->assertMatchesRegularExpression('/Character phases\s+2/', $display);
        $this->assertMatchesRegularExpression('/Weapon phases\s+2/', $display);
        $this->assertStringContainsString('Catalog saved.', $display);

        // Saved in the pool: a fresh reader sees it
        $this->assertTrue(new GiCatalog($pool)->isLoaded());
    }

    public function testAbortedBuildKeepsThePreviousCatalogAndFails(): void
    {
        $catalog = new HsrCatalog(new ArrayAdapter());
        $fixture = new IrminsulFixture(['v2/hsr/banner-weapons.json' => null]);
        $command = new HsrCatalogUpdateCommand(
            new HsrCatalogUpdater(new HsrCatalogBuilder($fixture->irminsulClient(), IrminsulFixture::normalizer(), new NullLogger()), $catalog, new NullLogger()),
            $catalog,
        );
        $tester = new CommandTester($command);

        $this->assertSame('hoyoverse:hsr-catalog:update', $command->getName());
        $this->assertSame(Command::FAILURE, $tester->execute([]));
        $this->assertStringContainsString('previous catalog kept', $tester->getDisplay());
        $this->assertFalse($catalog->isLoaded());
    }

    public function testZzzCommandUpdatesTheZzzCatalog(): void
    {
        $catalog = new ZzzCatalog(new ArrayAdapter());
        $command = new ZzzCatalogUpdateCommand(
            new ZzzCatalogUpdater(new ZzzCatalogBuilder(new IrminsulFixture()->irminsulClient(), IrminsulFixture::normalizer(), new NullLogger()), $catalog, new NullLogger()),
            $catalog,
        );
        $tester = new CommandTester($command);

        $this->assertSame('hoyoverse:zzz-catalog:update', $command->getName());
        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertStringContainsString('Zenless Zone Zero catalog updated', $tester->getDisplay());
        $this->assertCount(2, $catalog->getPhases('character'));
    }
}
