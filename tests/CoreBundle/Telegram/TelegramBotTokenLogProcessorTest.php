<?php

namespace App\Tests\CoreBundle\Telegram;

use App\CoreBundle\Service\Telegram\TelegramBotTokenLogProcessor;
use App\Tests\CoreBundle\FakeTelegramApi;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

class TelegramBotTokenLogProcessorTest extends TestCase
{
    private const string TOKEN = FakeTelegramApi::BOT_TOKEN;

    public function testTheSecretPartOfTheTokenIsMaskedInMessageAndContext(): void
    {
        $record = new LogRecord(new \DateTimeImmutable(), 'http_client', Level::Info, 'Request: "POST https://api.telegram.org/bot'.self::TOKEN.'/sendMessage"', [
            'url' => 'https://api.telegram.org/bot'.self::TOKEN.'/editMessageText',
            'nested' => ['https://api.telegram.org/bot'.self::TOKEN.'/getUpdates?offset=3'],
            'other' => 42,
        ]);

        $masked = (new TelegramBotTokenLogProcessor())($record);

        $this->assertSame('Request: "POST https://api.telegram.org/bot9030271366:***/sendMessage"', $masked->message);
        $this->assertSame('https://api.telegram.org/bot9030271366:***/editMessageText', $masked->context['url']);
        $this->assertSame('https://api.telegram.org/bot9030271366:***/getUpdates?offset=3', $masked->context['nested'][0]);
        $this->assertSame(42, $masked->context['other']);
        $this->assertStringNotContainsString('bot-secret', json_encode([$masked->message, $masked->context], \JSON_THROW_ON_ERROR));
    }

    public function testRecordsWithoutTheBotApiAreLeftAsTheyAre(): void
    {
        $record = new LogRecord(new \DateTimeImmutable(), 'app', Level::Info, 'Nothing about /robots', ['bot' => 'name']);

        $this->assertSame($record, (new TelegramBotTokenLogProcessor())($record));
    }
}
