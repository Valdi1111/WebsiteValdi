<?php

namespace App\Tests\GamesBundle\Presenter;

use App\CoreBundle\Model\Notification\EmbedField;
use App\GamesBundle\Model\Game\GameProfileInterface;
use App\GamesBundle\Presenter\GameProfileEmbedFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

class GameProfileEmbedFactoryTest extends TestCase
{
    /**
     * @param array<string, mixed> $values getter => value, on top of a complete profile
     */
    private function profile(array $values = []): GameProfileInterface
    {
        return $this->createConfiguredStub(GameProfileInterface::class, $values + [
            'getGameName' => 'Honkai: Star Rail',
            'getGameUid' => '100000001',
            'getNickname' => 'Trailblazer',
            'getParsedRegion' => 'Europe',
            'getLevel' => 70,
            'getIconUrl' => 'https://example.test/hsr.png',
            'getProviderIconUrl' => 'https://example.test/provider.ico',
        ]);
    }

    /**
     * @return list<array{0: ?string, 1: ?string, 2: ?string, 3: bool}>
     */
    private static function fields(array $fields): array
    {
        return array_map(static fn (EmbedField $field) => [$field->getName(), $field->getValue(), $field->getIcon(), $field->isInline()], $fields);
    }

    public function testHeaderOfTheProfile(): void
    {
        $factory = new GameProfileEmbedFactory(new MockClock('2026-03-17 15:00:00', 'UTC'));

        $embed = $factory->create($this->profile(), 'Stamina', 'Your stamina is full!');

        $this->assertSame('Honkai: Star Rail - Stamina', $embed->getTitle());
        $this->assertSame('Your stamina is full!', $embed->getDescription());
        $this->assertSame(GameProfileEmbedFactory::COLOR, $embed->getColor());
        $this->assertSame('Honkai: Star Rail', $embed->getFooterText());
        $this->assertSame('https://example.test/hsr.png', $embed->getFooterIconUrl());
        $this->assertEquals(new \DateTimeImmutable('2026-03-17 15:00:00', new \DateTimeZone('UTC')), $embed->getTimestamp());
        $this->assertSame([
            ['Player', '(`100000001`) Trailblazer', '👤', true],
            ['Region', 'Europe', '🌍', true],
            ['Rank', '70', '🏆', true],
        ], self::fields($embed->getFields()));
    }

    public function testDefaultsForAProfileWithoutDetails(): void
    {
        $factory = new GameProfileEmbedFactory(new MockClock());

        $embed = $factory->create($this->profile(['getNickname' => null, 'getParsedRegion' => null, 'getLevel' => null]), 'Dailies');

        $this->assertNull($embed->getDescription());
        $this->assertSame([
            ['Player', '(`100000001`) Player', '👤', true],
            ['Region', 'Global', '🌍', true],
            ['Rank', '1', '🏆', true],
        ], self::fields($embed->getFields()));
    }

    public function testFooterFallsBackToTheProviderIcon(): void
    {
        $factory = new GameProfileEmbedFactory(new MockClock());

        $this->assertSame(
            'https://example.test/provider.ico',
            $factory->create($this->profile(['getIconUrl' => null]), 'Dailies')->getFooterIconUrl()
        );
        $this->assertNull($factory->create($this->profile(['getIconUrl' => null, 'getProviderIconUrl' => null]), 'Dailies')->getFooterIconUrl());
    }
}
