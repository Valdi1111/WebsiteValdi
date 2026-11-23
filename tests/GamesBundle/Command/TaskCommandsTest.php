<?php

namespace App\Tests\GamesBundle\Command;

use App\GamesBundle\Command\AbstractTaskCommand;
use App\GamesBundle\Model\Task\ManualTask;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LazyCommand;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Every provider registers its "<provider>:task" command, and every task of its schedule is described there, so a
 * new scheduled task cannot be added without saying what it does and whether it runs per profile.
 */
class TaskCommandsTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function providers(): iterable
    {
        yield 'HoYoverse' => ['hoyoverse:task', 'hoyoverse'];
        yield 'Gryphline' => ['gryphline:task', 'gryphline'];
        yield 'Perfect World' => ['perfectworld:task', 'perfectworld'];
    }

    #[DataProvider('providers')]
    public function testEveryScheduledTaskIsDescribed(string $commandName, string $providerKey): void
    {
        $application = new Application(self::bootKernel());
        $command = $application->find($commandName);
        if ($command instanceof LazyCommand) {
            $command = $command->getCommand();
        }
        self::assertInstanceOf(AbstractTaskCommand::class, $command);

        /** @var array<string, ManualTask> $manualTasks */
        $manualTasks = new \ReflectionMethod($command, 'getManualTasks')->invoke($command);
        $scheduledTasks = static::getContainer()->getParameter($providerKey . '.tasks');
        self::assertIsArray($scheduledTasks);

        self::assertSame([], array_values(array_diff(array_keys($scheduledTasks), array_keys($manualTasks))), 'Scheduled tasks without a ManualTask');
        foreach ($manualTasks as $key => $manualTask) {
            self::assertNotSame('', trim($manualTask->description), \sprintf('Task "%s" has no description', $key));
        }

        $tester = new CommandTester($command);
        self::assertSame(Command::SUCCESS, $tester->execute(['--list' => true]));
        foreach (array_keys($scheduledTasks) as $key) {
            self::assertStringContainsString($key, $tester->getDisplay());
        }
    }
}
