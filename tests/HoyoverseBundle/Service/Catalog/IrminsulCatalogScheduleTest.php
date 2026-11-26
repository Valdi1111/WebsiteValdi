<?php

namespace App\Tests\HoyoverseBundle\Service\Catalog;

use App\HoyoverseBundle\Message\Batch\GiCatalogUpdateMessage;
use App\HoyoverseBundle\Message\Batch\HsrCatalogUpdateMessage;
use App\HoyoverseBundle\Message\Batch\ZzzCatalogUpdateMessage;
use App\HoyoverseBundle\MessageHandler\Batch\GiCatalogUpdateHandler;
use App\HoyoverseBundle\MessageHandler\Batch\HsrCatalogUpdateHandler;
use App\HoyoverseBundle\MessageHandler\Batch\ZzzCatalogUpdateHandler;
use App\HoyoverseBundle\Scheduler\ScheduleProvider;
use App\HoyoverseBundle\Service\Catalog\GiCatalog;
use App\HoyoverseBundle\Service\Catalog\GiCatalogBuilder;
use App\HoyoverseBundle\Service\Catalog\GiCatalogUpdater;
use App\HoyoverseBundle\Service\Catalog\HsrCatalog;
use App\HoyoverseBundle\Service\Catalog\HsrCatalogBuilder;
use App\HoyoverseBundle\Service\Catalog\HsrCatalogUpdater;
use App\HoyoverseBundle\Service\Catalog\ZzzCatalog;
use App\HoyoverseBundle\Service\Catalog\ZzzCatalogBuilder;
use App\HoyoverseBundle\Service\Catalog\ZzzCatalogUpdater;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\Messenger\Message\RedispatchMessage;
use Symfony\Component\Scheduler\Generator\MessageContext;

/**
 * The weekly catalog updates run on the hoyoverse schedule and transport, like the other HoYoverse tasks.
 */
class IrminsulCatalogScheduleTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{string, class-string, string}>
     */
    public static function taskProvider(): iterable
    {
        yield 'Genshin Impact' => ['gi_catalog_update', GiCatalogUpdateMessage::class, '0 6 * * 1'];
        yield 'Honkai: Star Rail' => ['hsr_catalog_update', HsrCatalogUpdateMessage::class, '5 6 * * 1'];
        yield 'Zenless Zone Zero' => ['zzz_catalog_update', ZzzCatalogUpdateMessage::class, '10 6 * * 1'];
    }

    #[DataProvider('taskProvider')]
    public function testCatalogUpdateIsConfiguredWeekly(string $task, string $messageClass, string $cron): void
    {
        $container = static::getContainer();

        $this->assertTrue($container->getParameter(sprintf('hoyoverse.task.%s.enabled', $task)));
        $this->assertSame($cron, $container->getParameter(sprintf('hoyoverse.task.%s.cron', $task)));
        $this->assertSame($messageClass, $container->getParameter(sprintf('hoyoverse.task.%s.message_class', $task)));
        $this->assertFalse($container->getParameter(sprintf('hoyoverse.task.%s.regional', $task)));
    }

    public function testCatalogUpdatesAreRedispatchedToTheHoyoverseTransport(): void
    {
        $tasks = [];
        foreach (self::taskProvider() as [$task, $messageClass, $cron]) {
            $tasks[$task] = ['enabled' => true, 'message_class' => $messageClass, 'cron' => $cron, 'regional' => false, 'jitter' => null];
        }
        $schedule = new ScheduleProvider(new ParameterBag([
            'hoyoverse.tasks' => $tasks,
            'hoyoverse.timezones' => ['SEA' => 'Asia/Shanghai', 'EU' => 'Europe/Paris', 'NA' => 'America/New_York'],
        ]))->getSchedule();

        $recurringMessages = $schedule->getRecurringMessages();
        $this->assertCount(3, $recurringMessages);

        $classes = [];
        foreach ($recurringMessages as $recurringMessage) {
            $context = new MessageContext('hoyoverse', $recurringMessage->getId(), $recurringMessage->getTrigger(), new \DateTimeImmutable());
            $messages = iterator_to_array($recurringMessage->getMessages($context), false);
            $this->assertInstanceOf(RedispatchMessage::class, $messages[0]);
            $this->assertSame('hoyoverse', $messages[0]->transportNames);
            $classes[] = $messages[0]->envelope::class;
        }
        $this->assertSame([GiCatalogUpdateMessage::class, HsrCatalogUpdateMessage::class, ZzzCatalogUpdateMessage::class], $classes);
    }

    public function testHandlersRebuildTheirCatalog(): void
    {
        $fixture = new IrminsulFixture();
        $normalizer = IrminsulFixture::normalizer();
        $gi = new GiCatalog(new ArrayAdapter());
        $hsr = new HsrCatalog(new ArrayAdapter());
        $zzz = new ZzzCatalog(new ArrayAdapter());

        new GiCatalogUpdateHandler(new GiCatalogUpdater(new GiCatalogBuilder($fixture->irminsulClient(), $normalizer, new NullLogger()), $gi, new NullLogger()))(new GiCatalogUpdateMessage());
        new HsrCatalogUpdateHandler(new HsrCatalogUpdater(new HsrCatalogBuilder($fixture->irminsulClient(), $normalizer, new NullLogger()), $hsr, new NullLogger()))(new HsrCatalogUpdateMessage());
        new ZzzCatalogUpdateHandler(new ZzzCatalogUpdater(new ZzzCatalogBuilder($fixture->irminsulClient(), $normalizer, new NullLogger()), $zzz, new NullLogger()))(new ZzzCatalogUpdateMessage());

        $this->assertSame('Zhongli', $gi->findItem('10000030')['name']);
        $this->assertSame('Seele', $hsr->findItem('1102')['name']);
        $this->assertSame('Burnice', $zzz->findItem('1171')['name']);
        $this->assertSame(['genshin', 'hsr', 'zzz'], array_values(array_unique(array_map(static fn (string $path): string => explode('/', $path)[1], $fixture->requestedPaths))));
    }

    public function testAbortedScheduledUpdateThrowsForTheTransportRetry(): void
    {
        $catalog = new GiCatalog(new ArrayAdapter());
        $updater = new GiCatalogUpdater(
            new GiCatalogBuilder(new IrminsulFixture(['v2/genshin/weapons.json' => null])->irminsulClient(), IrminsulFixture::normalizer(), new NullLogger()),
            $catalog,
            new NullLogger(),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('previous catalog kept');

        new GiCatalogUpdateHandler($updater)(new GiCatalogUpdateMessage());
    }
}
