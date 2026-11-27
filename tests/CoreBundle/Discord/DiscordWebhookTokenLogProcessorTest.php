<?php

namespace App\Tests\CoreBundle\Discord;

use App\CoreBundle\Service\Discord\DiscordWebhookTokenLogProcessor;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The token of the Discord webhook URLs never reaches a log, as the HttpClient logs every request URL (channel
 * http_client) and the answers to the interactions carry their token there.
 */
class DiscordWebhookTokenLogProcessorTest extends KernelTestCase
{
    private const string TOKEN = 'aW50ZXJhY3Rpb246c2VjcmV0LXRva2Vu_-x';
    private const string URL = 'https://discord.com/api/v10/webhooks/1180000000000000001/'.self::TOKEN.'/messages/@original';

    /**
     * @param array<mixed> $context
     */
    private static function record(string $message, array $context = []): LogRecord
    {
        return new LogRecord(new \DateTimeImmutable(), 'http_client', Level::Info, $message, $context);
    }

    public function testTheTokenOfAWebhookUrlIsMaskedInTheMessageAndTheContext(): void
    {
        $record = new DiscordWebhookTokenLogProcessor()(self::record('Request: "PATCH '.self::URL.'"', [
            'url' => self::URL,
            'nested' => ['urls' => ['https://discord.com/api/webhooks/1000000000000000000/notifier-webhook-token?wait=true']],
            'http_code' => 200,
        ]));

        $this->assertSame('Request: "PATCH https://discord.com/api/v10/webhooks/1180000000000000001/***/messages/@original"', $record->message);
        $this->assertSame('https://discord.com/api/v10/webhooks/1180000000000000001/***/messages/@original', $record->context['url']);
        $this->assertSame(['https://discord.com/api/webhooks/1000000000000000000/***?wait=true'], $record->context['nested']['urls']);
        $this->assertSame(200, $record->context['http_code']);
    }

    public function testOtherRecordsAreLeftAsTheyAre(): void
    {
        $record = self::record('Request: "GET https://discord.com/api/v10/applications/@me"', ['url' => 'https://discord.com/api/v10/users/@me']);

        $this->assertSame($record, new DiscordWebhookTokenLogProcessor()($record));
    }

    public function testEveryLoggerOfTheContainerMasksTheToken(): void
    {
        $handler = new TestHandler();
        foreach (['monolog.logger.http_client', 'monolog.logger.messenger', 'logger'] as $id) {
            $logger = static::getContainer()->get($id);
            $this->assertInstanceOf(Logger::class, $logger);
            $logger->pushHandler($handler);
            $logger->info('Request: "PATCH '.self::URL.'"', ['url' => self::URL]);
            $logger->popHandler();
        }

        $this->assertCount(3, $handler->getRecords());
        foreach ($handler->getRecords() as $record) {
            $this->assertStringNotContainsString(self::TOKEN, $record->message.json_encode($record->context));
            $this->assertStringContainsString('webhooks/1180000000000000001/***/messages/@original', $record->message);
        }
    }
}
