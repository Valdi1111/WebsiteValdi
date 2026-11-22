<?php

namespace App\Tests\GryphlineBundle\Service\Catalog;

use App\GryphlineBundle\Message\Batch\EndfieldCatalogUpdateMessage;
use App\GryphlineBundle\MessageHandler\Batch\EndfieldCatalogUpdateHandler;
use App\GryphlineBundle\Scheduler\ScheduleProvider;
use App\GryphlineBundle\Service\Catalog\EndfieldCatalog;
use App\GryphlineBundle\Service\Catalog\EndfieldCatalogBuilder;
use App\GryphlineBundle\Service\Catalog\EndfieldCatalogUpdater;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\Messenger\Message\RedispatchMessage;
use Symfony\Component\Scheduler\Generator\MessageContext;

/**
 * The weekly catalog update runs on the gryphline schedule and transport, like the other Gryphline tasks.
 */
class EndfieldCatalogScheduleTest extends KernelTestCase
{
    public function testCatalogUpdateIsConfiguredWeekly(): void
    {
        $container = static::getContainer();

        $this->assertTrue($container->getParameter('gryphline.task.catalog_update.enabled'));
        $this->assertSame('0 6 * * 1', $container->getParameter('gryphline.task.catalog_update.cron'));
        $this->assertSame(EndfieldCatalogUpdateMessage::class, $container->getParameter('gryphline.task.catalog_update.message_class'));
        $this->assertFalse($container->getParameter('gryphline.task.catalog_update.regional'));
    }

    public function testCatalogUpdateIsRedispatchedToTheGryphlineTransport(): void
    {
        $schedule = new ScheduleProvider(new ParameterBag([
            'gryphline.tasks' => [
                'catalog_update' => ['enabled' => true, 'message_class' => EndfieldCatalogUpdateMessage::class, 'cron' => '0 6 * * 1', 'regional' => false, 'jitter' => null],
            ],
            'gryphline.timezones' => ['US_EU' => 'Europe/Paris', 'SEA' => 'Asia/Shanghai'],
        ]))->getSchedule();

        $recurringMessages = $schedule->getRecurringMessages();
        $this->assertCount(1, $recurringMessages);

        $context = new MessageContext('gryphline', $recurringMessages[0]->getId(), $recurringMessages[0]->getTrigger(), new \DateTimeImmutable());
        $messages = iterator_to_array($recurringMessages[0]->getMessages($context), false);
        $this->assertInstanceOf(RedispatchMessage::class, $messages[0]);
        $this->assertSame('gryphline', $messages[0]->transportNames);
        $this->assertInstanceOf(EndfieldCatalogUpdateMessage::class, $messages[0]->envelope);
    }

    public function testHandlerRebuildsTheCatalog(): void
    {
        $catalog = new EndfieldCatalog(new ArrayAdapter());

        new EndfieldCatalogUpdateHandler($this->createUpdater(new AkeDataFixture(), $catalog))(new EndfieldCatalogUpdateMessage());

        $this->assertSame(AkeDataFixture::LATEST, $catalog->getVersion());
        $this->assertSame('Chen Qianyu', $catalog->find('chr_0005_chen')['name']);
    }

    public function testAbortedScheduledUpdateThrowsForTheTransportRetry(): void
    {
        $catalog = new EndfieldCatalog(new ArrayAdapter());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('previous catalog kept');

        new EndfieldCatalogUpdateHandler($this->createUpdater(new AkeDataFixture(['manifest.json' => null]), $catalog))(new EndfieldCatalogUpdateMessage());
    }

    private function createUpdater(AkeDataFixture $fixture, EndfieldCatalog $catalog): EndfieldCatalogUpdater
    {
        return new EndfieldCatalogUpdater(new EndfieldCatalogBuilder($fixture->client(), new NullLogger()), $catalog, new NullLogger());
    }
}
