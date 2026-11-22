<?php

namespace App\Tests\HoyoverseBundle\DependencyInjection;

use App\HoyoverseBundle\HoyoverseBundle;
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

    public function testEndgamesReminderWindowDefaultsToSevenDays(): void
    {
        $config = (new Processor())->processConfiguration($this->configuration(), []);

        $this->assertSame(['days_before_end' => 7], $config['endgames_reminder']);
    }

    public function testEndgamesReminderWindowIsConfigurable(): void
    {
        $config = (new Processor())->processConfiguration($this->configuration(), [['endgames_reminder' => ['days_before_end' => 3]]]);

        $this->assertSame(3, $config['endgames_reminder']['days_before_end']);
    }

    public function testEndgamesReminderWindowNeedsAtLeastOneDay(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        (new Processor())->processConfiguration($this->configuration(), [['endgames_reminder' => ['days_before_end' => 0]]]);
    }
}
