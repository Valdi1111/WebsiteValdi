<?php

namespace App\GamesBundle\Command;

use App\GamesBundle\Message\FeatureFlagMessageInterface;
use App\GamesBundle\Model\Game\GameProfileInterface;
use App\GamesBundle\Model\Task\ManualTask;
use Doctrine\Persistence\ObjectRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\Attribute\AsMessage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\WrappedExceptionsInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\SentStamp;
use Symfony\Component\PropertyAccess\PropertyAccess;

/**
 * Base of the "<provider>:task" commands: runs a task of the provider schedule by hand.
 *
 * - Without --profile-id it dispatches the batch message of the schedule (one per region for a regional task), so the
 *   batch handler picks the eligible profiles exactly as the scheduler does.
 * - With --profile-id it dispatches the per-profile messages of the task for those profiles only, whatever their
 *   feature flags (the flag is shown as a hint).
 * - Messages are queued on their transport, or handled in this process with --sync: a ReceivedStamp makes Messenger
 *   skip the transport, so errors show up here. Messages dispatched by a handler are still queued.
 *
 * Exit code: failure on invalid input, or when a message could not be dispatched or its handler failed.
 *
 * No constructor here: each provider command promotes its own services and parameters.
 */
abstract class AbstractTaskCommand extends Command
{
    private bool $pickedInteractively = false;

    /**
     * Provider key: prefix of the `<provider>.tasks` parameters (e.g. "hoyoverse").
     */
    abstract protected function getProviderKey(): string;

    /**
     * Provider name used in the messages (e.g. "HoYoverse").
     */
    abstract protected function getProviderName(): string;

    /**
     * The `<provider>.tasks` parameter (see GameTasksConfiguration).
     *
     * @return array<string, array{enabled: bool, message_class: string, cron: ?string, regional: bool, jitter?: ?int}>
     */
    abstract protected function getScheduledTasks(): array;

    /**
     * The `<provider>.timezones` parameter: region key => IANA timezone.
     *
     * @return array<string, string>
     */
    abstract protected function getRegions(): array;

    /**
     * Descriptions and per-profile messages, keyed by task name. A task missing from the schedule runs per profile only.
     *
     * @return array<string, ManualTask>
     */
    abstract protected function getManualTasks(): array;

    /**
     * @return ObjectRepository<covariant GameProfileInterface>
     */
    abstract protected function getProfileRepository(): ObjectRepository;

    abstract protected function getMessageBus(): MessageBusInterface;

    protected function configure(): void
    {
        $provider = $this->getProviderName();

        $this
            ->addArgument('task', InputArgument::OPTIONAL, 'Task to run (see --list)', null, fn (): array => array_keys($this->getTasks()))
            ->addOption('profile-id', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, \sprintf('Run the task only for this %s game profile, whatever its settings (repeatable)', $provider))
            ->addOption('region', 'r', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Region of a regional task (repeatable, default: every region)', [], fn (): array => array_keys($this->getRegions()))
            ->addOption('sync', null, InputOption::VALUE_NONE, 'Handle the messages in this process instead of queuing them')
            ->addOption('list', 'l', InputOption::VALUE_NONE, 'List the tasks and exit')
            ->setHelp(<<<HELP
                The <info>%command.name%</info> command runs a {$provider} scheduled task now.
                Without a task name it asks which one to run (or lists them with <comment>--no-interaction</comment>).

                List the tasks:
                  <info>php %command.full_name% --list</info>

                Run a task as the scheduler does, for every eligible profile:
                  <info>php %command.full_name% <task></info>

                Only some regions of a regional task:
                  <info>php %command.full_name% <task> --region=<region></info>

                Only some profiles, whatever their settings, handled right now in this process:
                  <info>php %command.full_name% <task> --profile-id=12 --profile-id=15 --sync</info>

                Queued messages run when a worker consumes their transport (<comment>messenger:consume</comment>).
                HELP);
    }

    protected function interact(InputInterface $input, OutputInterface $output): void
    {
        if ($input->getArgument('task') !== null || $input->getOption('list')) {
            return;
        }

        $io = new SymfonyStyle($input, $output);
        $tasks = $this->getTasks();

        $choices = [];
        foreach ($tasks as $key => $task) {
            $choices[$key] = $this->describeTask($task);
        }
        $key = (string) $io->choice(\sprintf('Which %s task do you want to run?', $this->getProviderName()), $choices);
        $input->setArgument('task', $key);
        $this->pickedInteractively = true;

        $manual = $tasks[$key]['manual'];
        if ($manual?->supportsProfiles() && $input->getOption('profile-id') === []) {
            $canRunAsBatch = $tasks[$key]['schedule'] !== null;
            $ids = (string) $io->ask(
                $canRunAsBatch ? 'Game profile IDs, comma separated (empty: every eligible profile)' : 'Game profile IDs, comma separated',
                null,
                static function (?string $answer) use ($canRunAsBatch): string {
                    $answer = trim((string) $answer);
                    if ($answer === '' && !$canRunAsBatch) {
                        throw new \RuntimeException('This task runs per profile only: give at least one ID.');
                    }

                    return $answer;
                },
            );
            if ($ids !== '') {
                $input->setOption('profile-id', array_map('trim', explode(',', $ids)));
            }
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $tasks = $this->getTasks();
        $taskArgument = $input->getArgument('task');

        if ($input->getOption('list') || $taskArgument === null) {
            $this->renderTaskList($io, $tasks);
            if (!$input->getOption('list')) {
                $io->note(\sprintf('Pass a task name to run it, e.g. "php bin/console %s %s".', $this->getName(), array_key_first($tasks)));
            }

            return Command::SUCCESS;
        }

        $key = $this->resolveTaskKey((string) $taskArgument, $tasks);
        if ($key === null) {
            $io->error(\sprintf('Unknown %s task "%s".%s', $this->getProviderName(), $taskArgument, $this->suggestTasks((string) $taskArgument, $tasks)));

            return Command::FAILURE;
        }

        $task = $tasks[$key];
        $profileIds = $input->getOption('profile-id');
        $regions = $input->getOption('region');
        $sync = (bool) $input->getOption('sync');

        $io->title(\sprintf('%s task "%s"', $this->getProviderName(), $key));
        $io->text($this->describeTask($task));
        $io->newLine();

        try {
            if ($profileIds !== []) {
                $profiles = $this->resolveProfiles($io, $key, $task, $profileIds, $regions);
                $question = \sprintf('Run "%s" for %d profile(s)%s?', $key, \count($profiles), $sync ? ' in this process' : '');
            } else {
                $messages = $this->buildBatchMessages($io, $key, $task, $regions);
                $question = \sprintf('%s %d batch message(s)?', $sync ? 'Handle' : 'Queue', \count($messages));
            }
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        // Before building the per-profile messages: a task may start a tracked operation while building them
        if ($this->pickedInteractively && !$io->confirm($question, true)) {
            $io->comment('Nothing dispatched.');

            return Command::SUCCESS;
        }

        if (isset($profiles)) {
            $messages = [];
            $skipped = [];
            foreach ($profiles as $profile) {
                $profileMessages = ($task['manual']->forProfile)($profile);
                if ($profileMessages === []) {
                    $skipped[] = \sprintf('#%d (%s)', $profile->getId(), $profile->getGameName() ?? 'N/A');
                    continue;
                }
                foreach ($profileMessages as $message) {
                    $messages[] = [$message, \sprintf('profile #%d', $profile->getId())];
                }
            }

            if ($skipped !== []) {
                $io->warning(\sprintf('Skipped, the task does not apply to their game: %s.', implode(', ', $skipped)));
            }
        }

        if ($messages === []) {
            $io->comment('Nothing dispatched.');

            return Command::SUCCESS;
        }

        return $this->dispatchAll($io, $messages, $sync, $profileIds === []);
    }

    /**
     * Schedule tasks first, in configuration order, then the tasks that run per profile only.
     *
     * @return array<string, array{schedule: ?array{enabled: bool, message_class: string, cron: ?string, regional: bool, jitter?: ?int}, manual: ?ManualTask}>
     */
    private function getTasks(): array
    {
        $tasks = [];
        foreach ($this->getScheduledTasks() as $key => $schedule) {
            $tasks[$key] = ['schedule' => $schedule, 'manual' => null];
        }
        foreach ($this->getManualTasks() as $key => $manual) {
            $tasks[$key] = ['schedule' => $tasks[$key]['schedule'] ?? null, 'manual' => $manual];
        }

        return $tasks;
    }

    /**
     * @param array{schedule: ?array<string, mixed>, manual: ?ManualTask} $task
     */
    private function describeTask(array $task): string
    {
        if ($task['manual'] !== null) {
            return $task['manual']->description;
        }

        return self::shortClassName((string) $task['schedule']['message_class']);
    }

    /**
     * @param array<string, array{schedule: ?array<string, mixed>, manual: ?ManualTask}> $tasks
     */
    private function renderTaskList(SymfonyStyle $io, array $tasks): void
    {
        $io->title(\sprintf('%s tasks', $this->getProviderName()));

        $regions = implode(', ', array_keys($this->getRegions()));
        $rows = [];
        foreach ($tasks as $key => $task) {
            $schedule = $task['schedule'];
            $when = match (true) {
                $schedule === null => '<comment>per profile only</comment>',
                !$schedule['enabled'] => '<comment>disabled</comment>',
                empty($schedule['cron']) => '<comment>not scheduled</comment>',
                default => (string) $schedule['cron'],
            };
            if ($schedule !== null && $schedule['regional']) {
                $when .= \sprintf("\nper region: %s", $regions);
            }

            $description = $this->describeTask($task);
            if ($task['manual']?->note !== null) {
                $description .= "\n<comment>" . $task['manual']->note . '</comment>';
            }

            $rows[] = [
                '<info>' . $key . '</info>',
                $description,
                $when,
                $task['manual']?->supportsProfiles() ? 'yes' : '-',
            ];
        }

        $io->table(['Task', 'What it does', 'Schedule', '--profile-id'], $rows);
    }

    /**
     * @param array<string, mixed> $tasks
     */
    private function resolveTaskKey(string $argument, array $tasks): ?string
    {
        $normalized = strtolower(str_replace('-', '_', trim($argument)));

        return \array_key_exists($normalized, $tasks) ? $normalized : null;
    }

    /**
     * @param array<string, mixed> $tasks
     */
    private function suggestTasks(string $argument, array $tasks): string
    {
        $normalized = strtolower(str_replace('-', '_', trim($argument)));
        $suggestions = array_filter(array_keys($tasks), static function (string $key) use ($normalized): bool {
            if ($normalized === '') {
                return false;
            }
            if (str_contains($key, $normalized) || levenshtein($key, $normalized) <= 3) {
                return true;
            }

            // Close to a word of the name, or to two consecutive words written as one ("checkin"), or same start ("daily")
            $parts = explode('_', $key);
            $words = $parts;
            for ($i = 0; $i < \count($parts) - 1; ++$i) {
                $words[] = $parts[$i] . $parts[$i + 1];
            }
            $maxDistance = max(1, intdiv(\strlen($normalized), 3) + 1);
            $prefix = \strlen($normalized) >= 4 ? substr($normalized, 0, 4) : null;
            foreach ($words as $word) {
                if (\strlen($word) > 2 && levenshtein($word, $normalized) <= $maxDistance) {
                    return true;
                }
                if ($prefix !== null && str_starts_with($word, $prefix)) {
                    return true;
                }
            }

            return false;
        });

        if ($suggestions !== []) {
            return \sprintf(' Did you mean: %s?', implode(', ', $suggestions));
        }

        return ' Run with --list to see the tasks.';
    }

    /**
     * @param array{schedule: ?array{enabled: bool, message_class: string, cron: ?string, regional: bool}, manual: ?ManualTask} $task
     * @param list<string> $regions
     *
     * @return list<array{object, string}> Message and target label
     */
    private function buildBatchMessages(SymfonyStyle $io, string $key, array $task, array $regions): array
    {
        $schedule = $task['schedule'];
        if ($schedule === null) {
            throw new \InvalidArgumentException(\sprintf('The "%s" task runs per profile only: pass --profile-id.', $key));
        }
        if (!$schedule['enabled']) {
            $io->note('This task is disabled in the schedule: it is dispatched anyway.');
        }

        $class = $schedule['message_class'];
        if (!$schedule['regional']) {
            if ($regions !== []) {
                throw new \InvalidArgumentException(\sprintf('The "%s" task is not regional: --region does not apply.', $key));
            }

            return [[new $class(), 'every eligible profile']];
        }

        $knownRegions = array_keys($this->getRegions());
        $unknownRegions = array_diff($regions, $knownRegions);
        if ($unknownRegions !== []) {
            throw new \InvalidArgumentException(\sprintf('Unknown region(s): %s. Regions: %s.', implode(', ', $unknownRegions), implode(', ', $knownRegions)));
        }

        $messages = [];
        foreach ($regions !== [] ? array_values(array_unique($regions)) : $knownRegions as $region) {
            $messages[] = [new $class($region), \sprintf('eligible profiles of region %s', $region)];
        }

        return $messages;
    }

    /**
     * Checks that the task runs per profile and that every profile exists, then shows them.
     *
     * @param array{schedule: ?array{message_class: string}, manual: ?ManualTask} $task
     * @param list<string> $profileIds
     * @param list<string> $regions
     *
     * @return list<GameProfileInterface>
     */
    private function resolveProfiles(SymfonyStyle $io, string $key, array $task, array $profileIds, array $regions): array
    {
        $manual = $task['manual'];
        if ($manual === null || !$manual->supportsProfiles()) {
            throw new \InvalidArgumentException(\sprintf('The "%s" task only runs for every eligible profile: drop --profile-id.', $key));
        }
        if ($regions !== []) {
            throw new \InvalidArgumentException('--region only applies without --profile-id: the per-profile messages do not depend on the region.');
        }

        $profiles = [];
        $missing = [];
        foreach (array_unique($profileIds) as $profileId) {
            $profile = ctype_digit((string) $profileId) ? $this->getProfileRepository()->find((int) $profileId) : null;
            if ($profile === null) {
                $missing[] = $profileId;
                continue;
            }
            $profiles[] = $profile;
        }
        if ($missing !== []) {
            throw new \InvalidArgumentException(\sprintf('%s game profile(s) not found: %s.', $this->getProviderName(), implode(', ', $missing)));
        }

        $this->renderProfiles($io, $profiles, $task['schedule']['message_class'] ?? null);

        return $profiles;
    }

    /**
     * @param list<GameProfileInterface> $profiles
     * @param string|null $batchClass Batch message of the task: its feature flag is shown, since --profile-id ignores it
     */
    private function renderProfiles(SymfonyStyle $io, array $profiles, ?string $batchClass): void
    {
        $flag = null;
        if ($batchClass !== null && is_subclass_of($batchClass, FeatureFlagMessageInterface::class)) {
            /** @var FeatureFlagMessageInterface $batch */
            $batch = new \ReflectionClass($batchClass)->newInstanceWithoutConstructor();
            $flag = $batch->getFeatureFlagField();
        }
        $accessor = PropertyAccess::createPropertyAccessor();

        $rows = [];
        foreach ($profiles as $profile) {
            $row = [$profile->getId(), $profile->getGameName() ?? 'N/A', $profile->getNickname() ?? 'N/A', $profile->getGameUid() ?? 'N/A'];
            if ($flag !== null) {
                $enabled = $accessor->isReadable($profile, $flag) ? $accessor->getValue($profile, $flag) : null;
                $row[] = match ($enabled) {
                    true => 'on',
                    false => '<comment>off</comment> (skipped by the batch)',
                    default => 'N/A',
                };
            }
            $rows[] = $row;
        }

        $headers = ['ID', 'Game', 'Nickname', 'UID'];
        if ($flag !== null) {
            $headers[] = $flag;
        }
        $io->table($headers, $rows);
    }

    /**
     * @param list<array{object, string}> $messages
     */
    private function dispatchAll(SymfonyStyle $io, array $messages, bool $sync, bool $batch): int
    {
        $rows = [];
        $errors = [];
        $transports = [];

        foreach ($messages as [$message, $target]) {
            $name = self::shortClassName(Envelope::wrap($message)->getMessage()::class);
            $stamps = $sync ? [new ReceivedStamp($this->getTransportName(Envelope::wrap($message)->getMessage()))] : [];

            try {
                $envelope = $this->getMessageBus()->dispatch($message, $stamps);
            } catch (\Throwable $e) {
                $errors[] = \sprintf('%s (%s): %s', $name, $target, self::describeError($e));
                $rows[] = [$name, $target, '<error>failed</error>'];
                continue;
            }

            [$outcome, $transport] = self::describeOutcome($envelope);
            if ($transport !== null) {
                $transports[$transport] = true;
            }
            $rows[] = [$name, $target, $outcome];
        }

        $io->table(['Message', 'Target', 'Result'], $rows);

        $delayed = array_filter($messages, static fn (array $entry): bool => $entry[0] instanceof Envelope && $entry[0]->last(DelayStamp::class) !== null);
        if ($sync && $delayed !== []) {
            $io->comment('--sync ignores the delays between these messages: they ran one after the other.');
        }
        if ($sync) {
            $io->comment($batch
                ? 'The batch handlers ran here; the per-profile messages they dispatched, and the notifications, are queued for the workers.'
                : 'The handlers ran here; the messages they dispatched (notifications, next pages) are queued for the workers.');
        }
        if ($transports !== []) {
            $io->comment(\sprintf('Queued messages run when a worker consumes them: php bin/console messenger:consume %s', implode(' ', array_keys($transports))));
        }

        if ($errors !== []) {
            $io->error(array_merge([\sprintf('%d of %d message(s) failed.', \count($errors), \count($messages))], $errors));

            return Command::FAILURE;
        }

        $io->success(\sprintf('%d message(s) %s.', \count($messages), $sync ? 'handled' : 'dispatched'));

        return Command::SUCCESS;
    }

    /**
     * Transport of the message per #[AsMessage], so that a handler bound to it would still match in --sync mode.
     */
    private function getTransportName(object $message): string
    {
        foreach (new \ReflectionClass($message)->getAttributes(AsMessage::class) as $attribute) {
            $transport = $attribute->newInstance()->transport;
            if (\is_string($transport)) {
                return $transport;
            }
            if (\is_array($transport) && $transport !== []) {
                return (string) reset($transport);
            }
        }

        return $this->getProviderKey();
    }

    /**
     * @return array{string, ?string} Result label and transport the message was queued on
     */
    private static function describeOutcome(Envelope $envelope): array
    {
        $sent = $envelope->last(SentStamp::class);
        if ($sent instanceof SentStamp) {
            $transport = $sent->getSenderAlias() ?? self::shortClassName($sent->getSenderClass());

            return [\sprintf('queued on %s', $transport), $transport];
        }

        $handlers = array_map(
            static fn (HandledStamp $stamp): string => self::shortClassName(explode('::', $stamp->getHandlerName())[0]),
            $envelope->all(HandledStamp::class)
        );

        return [$handlers !== [] ? \sprintf('<info>handled</info> by %s', implode(', ', $handlers)) : 'dispatched', null];
    }

    private static function describeError(\Throwable $e): string
    {
        if ($e instanceof WrappedExceptionsInterface && $e->getWrappedExceptions() !== []) {
            return implode('; ', array_map(
                static fn (\Throwable $wrapped): string => \sprintf('%s: %s', self::shortClassName($wrapped::class), $wrapped->getMessage()),
                $e->getWrappedExceptions()
            ));
        }

        return \sprintf('%s: %s', self::shortClassName($e::class), $e->getMessage());
    }

    private static function shortClassName(string $class): string
    {
        $position = strrpos($class, '\\');

        return $position === false ? $class : substr($class, $position + 1);
    }
}
