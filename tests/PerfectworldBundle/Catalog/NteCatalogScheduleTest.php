<?php

namespace App\Tests\PerfectworldBundle\Catalog;

use App\PerfectworldBundle\Message\Batch\NteCatalogUpdateMessage;
use App\PerfectworldBundle\MessageHandler\Batch\NteCatalogUpdateHandler;
use App\PerfectworldBundle\Scheduler\ScheduleProvider;
use App\PerfectworldBundle\Service\Catalog\NteCatalog;
use App\PerfectworldBundle\Service\Catalog\NteCatalogBackfiller;
use App\PerfectworldBundle\Service\Catalog\NteCatalogBuilder;
use App\PerfectworldBundle\Service\Catalog\NteCatalogUpdater;
use App\PerfectworldBundle\Repository\PerfectworldPullRepository;
use Doctrine\ORM\EntityManagerInterface;
use App\PerfectworldBundle\Service\Pull\ManualPullService;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\Messenger\Message\RedispatchMessage;
use Symfony\Component\Scheduler\Generator\MessageContext;

/**
 * The weekly catalog update runs on the perfectworld schedule and transport, like the other providers' tasks.
 */
class NteCatalogScheduleTest extends TestCase
{
    public function testCatalogUpdateIsRedispatchedToThePerfectworldTransport(): void
    {
        $schedule = (new ScheduleProvider(new ParameterBag([
            'perfectworld.tasks' => [
                'catalog_update' => ['enabled' => true, 'message_class' => NteCatalogUpdateMessage::class, 'cron' => '0 6 * * 1', 'regional' => false, 'jitter' => null],
            ],
            'perfectworld.timezones' => [],
        ])))->getSchedule();

        $recurringMessages = $schedule->getRecurringMessages();
        $this->assertCount(1, $recurringMessages);

        $context = new MessageContext('perfectworld', $recurringMessages[0]->getId(), $recurringMessages[0]->getTrigger(), new \DateTimeImmutable());
        $messages = iterator_to_array($recurringMessages[0]->getMessages($context), false);
        $this->assertInstanceOf(RedispatchMessage::class, $messages[0]);
        $this->assertSame('perfectworld', $messages[0]->transportNames);
        $this->assertInstanceOf(NteCatalogUpdateMessage::class, $messages[0]->envelope);
    }

    public function testHandlerRebuildsTheCatalog(): void
    {
        $catalog = new NteCatalog(new ArrayAdapter());
        $repository = $this->createStub(PerfectworldPullRepository::class);
        $repository->method('findBy')->willReturn([]);
        $updater = new NteCatalogUpdater(
            new NteCatalogBuilder((new NteAssetsFixture())->client(), $catalog, (new NteIrminsulFixture())->irminsulClient(), NteIrminsulFixture::normalizer(), new NullLogger()),
            $catalog,
            new NteCatalogBackfiller($catalog, $repository, $this->createStub(EntityManagerInterface::class), $this->createStub(ManualPullService::class), new LockFactory(new InMemoryStore()), new ServiceLocator([]), new NullLogger()),
            new NullLogger(),
        );

        (new NteCatalogUpdateHandler($updater))(new NteCatalogUpdateMessage());

        $this->assertSame(NteAssetsFixture::SHA, $catalog->getSha());
    }

    public function testAbortedScheduledUpdateThrowsForTheTransportRetry(): void
    {
        $catalog = new NteCatalog(new ArrayAdapter());
        $updater = new NteCatalogUpdater(
            new NteCatalogBuilder((new NteAssetsFixture(['README.md' => "> Version: 1.6.0 (CN)\n"]))->client(), $catalog, (new NteIrminsulFixture())->irminsulClient(), NteIrminsulFixture::normalizer(), new NullLogger()),
            $catalog,
            new NteCatalogBackfiller($catalog, $this->createStub(PerfectworldPullRepository::class), $this->createStub(EntityManagerInterface::class), $this->createStub(ManualPullService::class), new LockFactory(new InMemoryStore()), new ServiceLocator([]), new NullLogger()),
            new NullLogger(),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('previous catalog kept');
        (new NteCatalogUpdateHandler($updater))(new NteCatalogUpdateMessage());
    }
}
