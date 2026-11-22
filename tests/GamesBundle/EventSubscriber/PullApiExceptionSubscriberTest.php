<?php

namespace App\Tests\GamesBundle\EventSubscriber;

use App\GamesBundle\EventSubscriber\PullApiExceptionSubscriber;
use App\GamesBundle\Exception\ManualPullViolationException;
use App\GamesBundle\Exception\PullProfileLockedException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

class PullApiExceptionSubscriberTest extends TestCase
{
    public function testManualPullViolationBecomesA422(): void
    {
        $event = $this->dispatch('/api/hoyoverse/accounts/1/gameProfiles/2/pulls/manual', new ManualPullViolationException(
            ManualPullViolationException::POSSIBLE_DUPLICATE,
            'A recorded S pull of "Furina" exists on 2025-01-01 11:00:00. Is this another copy?',
        ));

        $response = $event->getResponse();
        $this->assertNotNull($response);
        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame([
            'type' => 'https://tools.ietf.org/html/rfc2616#section-10',
            'title' => 'Invalid manual pull',
            'status' => 422,
            'detail' => 'A recorded S pull of "Furina" exists on 2025-01-01 11:00:00. Is this another copy?',
            'code' => 'possible_duplicate',
        ], json_decode((string) $response->getContent(), true));
    }

    public function testLockedProfileBecomesA409(): void
    {
        $event = $this->dispatch('/api/gryphline/accounts/1/gameProfiles/2/pulls/manual', new PullProfileLockedException('Another pull import is currently running for profile #2.'));

        $response = $event->getResponse();
        $this->assertNotNull($response);
        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame([
            'type' => 'https://tools.ietf.org/html/rfc2616#section-10',
            'title' => 'Pull operation in progress',
            'status' => 409,
            'detail' => 'Another pull import is currently running for profile #2.',
            'code' => 'profile_locked',
        ], json_decode((string) $response->getContent(), true));
    }

    public function testOtherPathsAndExceptionsAreLeftAlone(): void
    {
        $this->assertNull($this->dispatch('/games/pulls', new ManualPullViolationException(ManualPullViolationException::NO_ROOM, 'No room.'))->getResponse());
        $this->assertNull($this->dispatch('/apiary', new PullProfileLockedException('Locked.'))->getResponse());
        $this->assertNull($this->dispatch('/api/hoyoverse/accounts', new \DomainException('Other.'))->getResponse());
    }

    private function dispatch(string $path, \Throwable $exception): ExceptionEvent
    {
        $event = new ExceptionEvent(
            $this->createStub(HttpKernelInterface::class),
            Request::create($path, 'POST'),
            HttpKernelInterface::MAIN_REQUEST,
            $exception,
        );
        (new PullApiExceptionSubscriber())($event);

        return $event;
    }
}
