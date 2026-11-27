<?php

namespace App\Tests\CoreBundle\Chat;

use App\CoreBundle\Exception\InvalidChatCommandLineException;
use App\CoreBundle\Model\Chat\ChatCommandLine;
use App\CoreBundle\Service\Chat\ChatCommandLineParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

class ChatCommandLineParserTest extends TestCase
{
    private function parse(string $line): ChatCommandLine
    {
        return new ChatCommandLineParser(new ChatTestKit()->commands())->parse($line);
    }

    /**
     * @return iterable<string, array{string, ChatCommandLine}>
     */
    public static function lines(): iterable
    {
        yield 'slash' => ['/help', new ChatCommandLine('help')];
        yield 'no slash, spaces, case' => ['  HELP  ', new ChatCommandLine('help')];
        yield 'bot name' => ['/help@ValdiBot', new ChatCommandLine('help', botName: 'ValdiBot')];
        yield 'positional in declared order' => ['/echo hi 3 yes', new ChatCommandLine('echo', arguments: ['text' => 'hi', 'times' => '3', 'loud' => 'yes'])];
        yield 'quotes group words' => ['/echo "two  words" times=2', new ChatCommandLine('echo', arguments: ['text' => 'two  words', 'times' => '2'])];
        yield 'typographic quotes' => ['/echo “two words”', new ChatCommandLine('echo', arguments: ['text' => 'two words'])];
        yield 'unclosed quote' => ['/echo "two words', new ChatCommandLine('echo', arguments: ['text' => 'two words'])];
        yield 'empty quotes' => ['/echo ""', new ChatCommandLine('echo', arguments: ['text' => ''])];
        yield 'named first, order of the options' => ['/echo TIMES=2 hello tone=calm', new ChatCommandLine('echo', arguments: ['text' => 'hello', 'times' => '2', 'tone' => 'calm'])];
        yield 'quoted value of a name' => ['/echo text="a b"', new ChatCommandLine('echo', arguments: ['text' => 'a b'])];
        yield 'quoted pair is a value' => ['/echo "times=2"', new ChatCommandLine('echo', arguments: ['text' => 'times=2'])];
        yield 'pair of an unknown name is a value' => ['/echo a=b', new ChatCommandLine('echo', arguments: ['text' => 'a=b'])];
        yield 'subcommand and its options' => ['/remind Stamina 120', new ChatCommandLine('remind', 'stamina', ['value' => '120'])];
        yield 'subcommand without options' => ['/remind list', new ChatCommandLine('remind', 'list')];
        yield 'subcommand missing' => ['/remind', new ChatCommandLine('remind')];
        yield 'unknown subcommand left to the dispatcher' => ['/remind cancel 1 2', new ChatCommandLine('remind', 'cancel')];
        yield 'unknown command left to the dispatcher' => ['/nope 1 2 3', new ChatCommandLine('nope')];
    }

    #[DataProvider('lines')]
    public function testLine(string $line, ChatCommandLine $expected): void
    {
        $this->assertEquals($expected, $this->parse($line));
    }

    #[TestWith(['/help me', 'Too many arguments for /help: it takes none.'])]
    #[TestWith(['/remind list all', 'Too many arguments for /remind list: it takes none.'])]
    #[TestWith(['/remind stamina 120 gi more', 'Too many arguments for /remind stamina: it takes value, game.'])]
    #[TestWith(['', 'Type a command, e.g. /help.'])]
    #[TestWith(['/', 'Type a command, e.g. /help.'])]
    #[TestWith(['/@ValdiBot', 'Type a command, e.g. /help.'])]
    public function testUnreadableLine(string $line, string $message): void
    {
        $this->expectException(InvalidChatCommandLineException::class);
        $this->expectExceptionMessage($message);

        $this->parse($line);
    }
}
