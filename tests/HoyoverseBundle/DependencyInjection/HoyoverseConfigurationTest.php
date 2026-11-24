<?php

namespace App\Tests\HoyoverseBundle\DependencyInjection;

use App\HoyoverseBundle\HoyoverseBundle;
use App\HoyoverseBundle\Service\GenshinImpactService;
use App\HoyoverseBundle\Service\ZenlessZoneZeroService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class HoyoverseConfigurationTest extends TestCase
{
    private function configuration(): ConfigurationInterface
    {
        return (new HoyoverseBundle())->getContainerExtension()->getConfiguration([], new ContainerBuilder());
    }

    private function process(array $config): array
    {
        return (new Processor())->processConfiguration($this->configuration(), [$config]);
    }

    public function testEndgameGamesAreFoundInTheGameServices(): void
    {
        // Honkai: Star Rail does not implement HasEndgamesInterface yet
        $this->assertSame(['gi' => GenshinImpactService::class, 'zzz' => ZenlessZoneZeroService::class], HoyoverseBundle::getEndgameGameClasses());
    }

    public function testEveryDeclaredModeGetsASevenDayWindowByDefault(): void
    {
        $this->assertSame(
            [
                'gi' => ['spiral_abyss' => ['days_before_end' => 7], 'imaginarium_theater' => ['days_before_end' => 7], 'stygian_onslaught' => ['days_before_end' => 7]],
                'zzz' => ['shiyu_defense' => ['days_before_end' => 7], 'deadly_assault' => ['days_before_end' => 7]],
            ],
            $this->process([])['endgames_reminder']
        );
    }

    public function testEachModeHasItsOwnWindow(): void
    {
        $config = $this->process(['endgames_reminder' => ['zzz' => ['deadly_assault' => ['days_before_end' => 3]]]]);

        $this->assertSame(3, $config['endgames_reminder']['zzz']['deadly_assault']['days_before_end']);
        $this->assertSame(7, $config['endgames_reminder']['zzz']['shiyu_defense']['days_before_end']);
    }

    /**
     * @return iterable<string, array{0: array}>
     */
    public static function invalidConfigProvider(): iterable
    {
        yield 'mistyped mode' => [['zzz' => ['deadly_asault' => ['days_before_end' => 3]]]];
        yield 'unknown game' => [['genshin' => ['spiral_abyss' => ['days_before_end' => 3]]]];
        yield 'old global window' => [['days_before_end' => 3]];
        yield 'less than one day' => [['zzz' => ['shiyu_defense' => ['days_before_end' => 0]]]];
    }

    #[DataProvider('invalidConfigProvider')]
    public function testInvalidWindowsAreConfigurationErrors(array $endgamesReminder): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process(['endgames_reminder' => $endgamesReminder]);
    }
}
