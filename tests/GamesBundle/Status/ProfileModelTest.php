<?php

namespace App\Tests\GamesBundle\Status;

use App\GamesBundle\Model\Profile\GameDescriptor;
use App\GamesBundle\Model\Profile\GameProfileRef;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ProfileModelTest extends TestCase
{
    public function testReferenceRoundTrip(): void
    {
        $ref = GameProfileRef::tryFromString('hoyoverse:42');

        $this->assertNotNull($ref);
        $this->assertSame('hoyoverse', $ref->provider);
        $this->assertSame(42, $ref->id);
        $this->assertSame('hoyoverse:42', (string) $ref);
        $this->assertTrue($ref->equals(new GameProfileRef('hoyoverse', 42)));
        $this->assertFalse($ref->equals(new GameProfileRef('gryphline', 42)));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidReferences(): iterable
    {
        yield 'no provider' => [':42'];
        yield 'no id' => ['hoyoverse:'];
        yield 'zero' => ['hoyoverse:0'];
        yield 'leading zero' => ['hoyoverse:042'];
        yield 'negative' => ['hoyoverse:-1'];
        yield 'upper case' => ['Hoyoverse:42'];
        yield 'extra part' => ['hoyoverse:42:1'];
        yield 'too large' => ['hoyoverse:99999999999999999999'];
        yield 'spaces' => [' hoyoverse:42'];
    }

    #[DataProvider('invalidReferences')]
    public function testInvalidReferencesAreRefused(string $value): void
    {
        $this->assertNull(GameProfileRef::tryFromString($value));
    }

    public function testInvalidReferenceCannotBeBuilt(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new GameProfileRef('hoyoverse', 0);
    }

    public function testDescriptorMatchesItsSlugAndAliasesIgnoringCase(): void
    {
        $game = new GameDescriptor('hoyoverse', 'hk4e_global', 'gi', 'Genshin Impact', aliases: ['genshin', 'genshin-impact']);

        $this->assertTrue($game->matches('gi'));
        $this->assertTrue($game->matches(' GI '));
        $this->assertTrue($game->matches('Genshin'));
        $this->assertFalse($game->matches('hsr'));
        $this->assertFalse($game->matches(''));
    }

    public function testSlugify(): void
    {
        $this->assertSame('genshin-impact', GameDescriptor::slugify('Genshin Impact'));
        $this->assertSame('arknights-endfield', GameDescriptor::slugify('Arknights: Endfield'));
    }
}
