<?php

namespace App\Tests\GryphlineBundle\Service\Catalog;

use App\GryphlineBundle\Message\Batch\AkeCatalogUpdateMessage;
use App\GryphlineBundle\MessageHandler\Batch\AkeCatalogUpdateHandler;
use App\GryphlineBundle\Scheduler\ScheduleProvider;
use App\GryphlineBundle\Service\Catalog\AkeCatalog;
use App\GryphlineBundle\Service\Catalog\AkeCatalogUpdater;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\Messenger\Message\RedispatchMessage;
use Symfony\Component\Scheduler\Generator\MessageContext;

/**
 * The weekly catalog update runs on the gryphline schedule and transport, like the other Gryphline tasks.
 */
class AkeCatalogScheduleTest extends KernelTestCase
{
    public function testCatalogUpdateIsConfiguredWeekly(): void
    {
        $container = static::getContainer();

        $this->assertTrue($container->getParameter('gryphline.task.catalog_update.enabled'));
        $this->assertSame('0 6 * * 1', $container->getParameter('gryphline.task.catalog_update.cron'));
        $this->assertSame(AkeCatalogUpdateMessage::class, $container->getParameter('gryphline.task.catalog_update.message_class'));
        $this->assertFalse($container->getParameter('gryphline.task.catalog_update.regional'));
    }

    public function testCatalogUpdateIsRedispatchedToTheGryphlineTransport(): void
    {
        $schedule = new ScheduleProvider(new ParameterBag([
            'gryphline.tasks' => [
                'catalog_update' => ['enabled' => true, 'message_class' => AkeCatalogUpdateMessage::class, 'cron' => '0 6 * * 1', 'regional' => false, 'jitter' => null],
            ],
            'gryphline.timezones' => ['US_EU' => 'Europe/Paris', 'SEA' => 'Asia/Shanghai'],
        ]))->getSchedule();

        $recurringMessages = $schedule->getRecurringMessages();
        $this->assertCount(1, $recurringMessages);

        $context = new MessageContext('gryphline', $recurringMessages[0]->getId(), $recurringMessages[0]->getTrigger(), new \DateTimeImmutable());
        $messages = iterator_to_array($recurringMessages[0]->getMessages($context), false);
        $this->assertInstanceOf(RedispatchMessage::class, $messages[0]);
        $this->assertSame('gryphline', $messages[0]->transportNames);
        $this->assertInstanceOf(AkeCatalogUpdateMessage::class, $messages[0]->envelope);
    }

    public function testHandlerRebuildsTheCatalog(): void
    {
        $catalog = new AkeCatalog(new ArrayAdapter());

        new AkeCatalogUpdateHandler($this->createUpdater(new AkeDataFixture(), $catalog))(new AkeCatalogUpdateMessage());

        $this->assertSame(AkeDataFixture::LATEST, $catalog->getVersion());
        $this->assertSame('Chen Qianyu', $catalog->find('chr_0005_chen')['name']);
    }

    public function testAbortedScheduledUpdateThrowsForTheTransportRetry(): void
    {
        $catalog = new AkeCatalog(new ArrayAdapter());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('previous catalog kept');

        new AkeCatalogUpdateHandler($this->createUpdater(new AkeDataFixture(['manifest.json' => null]), $catalog))(new AkeCatalogUpdateMessage());
    }

    private function createUpdater(AkeDataFixture $fixture, AkeCatalog $catalog): AkeCatalogUpdater
    {
        return new AkeCatalogUpdater($fixture->builder(previous: $catalog), $catalog, new NullLogger());
    }
}
