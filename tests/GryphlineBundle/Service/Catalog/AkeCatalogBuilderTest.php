<?php

namespace App\Tests\GryphlineBundle\Service\Catalog;

use App\GryphlineBundle\Service\Catalog\AkeCatalogBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class AkeCatalogBuilderTest extends TestCase
{
    public function testBuildsCompactCatalogOfTheLatestVersion(): void
    {
        $fixture = new AkeDataFixture();
        $catalog = $this->createBuilder($fixture)->build();

        $this->assertSame(AkeDataFixture::LATEST, $catalog['version']);
        $this->assertNotFalse(\DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $catalog['built_at']));

        // The item table is read (and reduced) before the big text table is downloaded
        $this->assertSame([
            'manifest.json',
            AkeDataFixture::LATEST_PATH . '/ItemTable.json',
            AkeDataFixture::LATEST_PATH . '/I18nTextTable_EN.json',
        ], $fixture->requestedPaths);

        $this->assertSame([
            'chr_0002_endminm' => ['id' => 'chr_0002_endminm', 'name' => 'The Endministrator', 'rarity' => 6, 'type' => 'char'],
            'chr_0005_chen' => ['id' => 'chr_0005_chen', 'name' => 'Chen Qianyu', 'rarity' => 5, 'type' => 'char'],
            'chr_0009_azrila' => ['id' => 'chr_0009_azrila', 'name' => 'Ember', 'rarity' => 6, 'type' => 'char'],
            'chr_0013_aglina' => ['id' => 'chr_0013_aglina', 'name' => 'Gilberta', 'rarity' => 6, 'type' => 'char'],
            'wpn_claym_0003' => ['id' => 'wpn_claym_0003', 'name' => 'Industry 0.1', 'rarity' => 4, 'type' => 'weapon'],
            'wpn_funnel_0011' => ['id' => 'wpn_funnel_0011', 'name' => 'Delivery Guaranteed', 'rarity' => 6, 'type' => 'weapon'],
        ], $catalog['items']);
    }

    public function testKeepsOnlyOperatorsAndWeaponsWithAnEnglishName(): void
    {
        $items = $this->createBuilder(new AkeDataFixture())->build()['items'];

        // Other item kinds resolve too, but are not operators or weapons
        $this->assertArrayNotHasKey('achv_adv_tundra_box_1', $items);
        $this->assertArrayNotHasKey('item_gold', $items);

        // Text id missing from the English table, blank text, no text id
        $this->assertArrayNotHasKey('wpn_pistol_0008', $items);
        $this->assertArrayNotHasKey('chr_0098_blank', $items);
        $this->assertArrayNotHasKey('chr_0099_noname', $items);
    }

    public function testRequestedVersionUsesItsTableCfgPath(): void
    {
        $fixture = new AkeDataFixture();
        $catalog = $this->createBuilder($fixture)->build(' ' . AkeDataFixture::OLDER . ' ');

        $this->assertSame(AkeDataFixture::OLDER, $catalog['version']);
        $this->assertSame(['chr_0005_chen'], array_keys($catalog['items']));
        $this->assertSame(AkeDataFixture::OLDER_PATH . '/ItemTable.json', $fixture->requestedPaths[1]);
    }

    public function testCatalogWithoutResolvedNamesThrows(): void
    {
        $fixture = new AkeDataFixture([AkeDataFixture::LATEST_PATH . '/I18nTextTable_EN.json' => ['42' => 'T-Creds']]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No operator or weapon name could be resolved');

        $this->createBuilder($fixture)->build();
    }

    public function testItemTableWithoutOperatorsOrWeaponsThrowsBeforeTheTextTable(): void
    {
        $fixture = new AkeDataFixture([AkeDataFixture::LATEST_PATH . '/ItemTable.json' => [
            'item_gold' => AkeDataFixture::itemRow('item_gold', 42, 3, 1),
        ]]);

        try {
            $this->createBuilder($fixture)->build();
            $this->fail('An item table without operators or weapons must abort the build.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('has no operator or weapon', $e->getMessage());
        }

        $this->assertNotContains(AkeDataFixture::LATEST_PATH . '/I18nTextTable_EN.json', $fixture->requestedPaths);
    }

    public function testUnknownVersionThrowsBeforeAnyTable(): void
    {
        $fixture = new AkeDataFixture();

        try {
            $this->createBuilder($fixture)->build('9.9.9@1-1');
            $this->fail('An unknown manifest version must abort the build.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('no version "9.9.9@1-1"', $e->getMessage());
        }

        $this->assertSame(['manifest.json'], $fixture->requestedPaths);
    }

    /**
     * @return iterable<string, array{string, mixed, string}>
     */
    public static function brokenSourceProvider(): iterable
    {
        $manifest = AkeDataFixture::manifest();

        yield 'manifest not found' => ['manifest.json', null, 'manifest.json could not be read'];
        yield 'manifest without latest' => ['manifest.json', ['latest' => null] + $manifest, 'no latest version'];
        yield 'unsafe table path' => ['manifest.json', ['versions' => [['id' => AkeDataFixture::LATEST, 'tableCfgPath' => 'public/../../etc']]] + $manifest, 'no usable tableCfgPath'];
        yield 'missing table path' => ['manifest.json', ['versions' => [['id' => AkeDataFixture::LATEST]]] + $manifest, 'no usable tableCfgPath'];
        yield 'item table not found' => [AkeDataFixture::LATEST_PATH . '/ItemTable.json', null, 'ItemTable.json could not be read'];
        yield 'invalid text table' => [AkeDataFixture::LATEST_PATH . '/I18nTextTable_EN.json', '{"broken', 'I18nTextTable_EN.json could not be read'];
    }

    #[DataProvider('brokenSourceProvider')]
    public function testBrokenSourceThrows(string $path, mixed $content, string $message): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($message);

        $this->createBuilder(new AkeDataFixture([$path => $content]))->build();
    }

    private function createBuilder(AkeDataFixture $fixture): AkeCatalogBuilder
    {
        return new AkeCatalogBuilder($fixture->client(), new NullLogger());
    }
}
