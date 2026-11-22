<?php

namespace App\Tests\HoyoverseBundle\Service\Pull;

use App\HoyoverseBundle\Repository\HoyoversePullRepository;
use App\HoyoverseBundle\Service\GenshinImpactService;
use App\HoyoverseBundle\Service\Pull\ManualPullSuggestionProvider;
use App\HoyoverseBundle\Service\Pull\NanokaCatalogService;
use App\HoyoverseBundle\Service\ZenlessZoneZeroService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class ManualPullSuggestionProviderTest extends TestCase
{
    public function testMergesCatalogSItemsWithRecordedOnesDedupedByIdAndName(): void
    {
        $catalog = $this->createStub(NanokaCatalogService::class);
        $catalog->method('getGiCatalog')->willReturn([
            'id_char' => [
                '10000089' => ['id' => '10000089', 'name' => 'Furina', 'rarity' => 5, 'icon' => 'x', 'item_type' => 'Character'],
                '10000005' => ['id' => '10000005', 'name' => 'Traveler', 'rarity' => 5, 'icon' => 'x', 'item_type' => 'Character'],
                '10000007' => ['id' => '10000007', 'name' => 'Traveler', 'rarity' => 5, 'icon' => 'x', 'item_type' => 'Character'],
                '10000032' => ['id' => '10000032', 'name' => 'Bennett', 'rarity' => 4, 'icon' => 'x', 'item_type' => 'Character'],
            ],
            'id_weapon' => [
                '11509' => ['id' => '11509', 'name' => 'Mistsplitter Reforged', 'rarity' => 5, 'icon' => 'x', 'item_type' => 'Weapon'],
            ],
        ]);
        $repository = $this->createMock(HoyoversePullRepository::class);
        $repository->expects($this->once())->method('findDistinctRecordedSItems')->with(GenshinImpactService::GAME_ID, 5)->willReturn([
            // Genshin pulls have no item id: matched by name
            ['itemId' => '', 'name' => 'furina', 'itemType' => 'Character', 'rarity' => 5],
            // Newer than the catalog
            ['itemId' => '', 'name' => 'Brand New', 'itemType' => 'Character', 'rarity' => 5],
        ]);
        $repository->expects($this->once())->method('findDistinctRecordedItemTypes')->with(GenshinImpactService::GAME_ID)->willReturn(['Weapon', 'Character']);

        $suggestions = (new ManualPullSuggestionProvider($catalog, $repository))->getSuggestions(new GenshinImpactService(new NullLogger()));

        $this->assertSame([
            ['item_id' => null, 'name' => 'Brand New', 'item_type' => 'Character', 'item_type_label' => 'Character', 'rarity' => 5],
            ['item_id' => '10000089', 'name' => 'Furina', 'item_type' => 'Character', 'item_type_label' => 'Character', 'rarity' => 5],
            ['item_id' => '11509', 'name' => 'Mistsplitter Reforged', 'item_type' => 'Weapon', 'item_type_label' => 'Weapon', 'rarity' => 5],
            ['item_id' => '10000005', 'name' => 'Traveler', 'item_type' => 'Character', 'item_type_label' => 'Character', 'rarity' => 5],
        ], $suggestions['items'], 'S only, one entry per name, sorted by name');
        $this->assertSame([['value' => 'Character', 'label' => 'Character'], ['value' => 'Weapon', 'label' => 'Weapon']], $suggestions['item_types']);
    }

    public function testFallsBackToRecordedItemsWithoutCatalogAndFindsBySuggestion(): void
    {
        $catalog = $this->createStub(NanokaCatalogService::class);
        $catalog->method('getZzzCatalog')->willReturn([]);
        $repository = $this->createStub(HoyoversePullRepository::class);
        $repository->method('findDistinctRecordedSItems')->willReturn([
            ['itemId' => '1041', 'name' => 'Soldier 11', 'itemType' => 'Agents', 'rarity' => 4],
            ['itemId' => '54004', 'name' => 'Plugboo', 'itemType' => 'Bangboo', 'rarity' => 4],
        ]);
        $repository->method('findDistinctRecordedItemTypes')->willReturn(['Agents', 'Bangboo', 'W-Engines']);

        $provider = new ManualPullSuggestionProvider($catalog, $repository);
        $zzz = new ZenlessZoneZeroService(new NullLogger());
        $suggestions = $provider->getSuggestions($zzz);

        $this->assertSame(['Plugboo', 'Soldier 11'], array_column($suggestions['items'], 'name'));
        $this->assertSame(['Agents', 'Bangboo', 'W-Engines'], array_column($suggestions['item_types'], 'value'));
        $this->assertSame('Bangboo', $provider->findSuggestion($zzz, '54004', 'whatever')['item_type'] ?? null, 'By item id first');
        $this->assertSame('Agents', $provider->findSuggestion($zzz, null, 'soldier-11')['item_type'] ?? null, 'Then by normalized name');
        $this->assertNull($provider->findSuggestion($zzz, null, 'Nobody'));
    }
}
