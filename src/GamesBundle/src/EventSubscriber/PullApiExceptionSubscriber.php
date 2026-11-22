<?php

namespace App\GamesBundle\EventSubscriber;

use App\GamesBundle\Exception\ManualPullViolationException;
use App\GamesBundle\Exception\PullProfileLockedException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Renders the pull domain exceptions of the API as problem details with a machine-readable code.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION)]
class PullApiExceptionSubscriber
{
    public function __invoke(ExceptionEvent $event): void
    {
        if (!str_starts_with($event->getRequest()->getPathInfo(), '/api/')) {
            return;
        }

        $exception = $event->getThrowable();
        $response = match (true) {
            $exception instanceof ManualPullViolationException => $this->createResponse(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'Invalid manual pull',
                $exception->getMessage(),
                $exception->violation,
            ),
            $exception instanceof PullProfileLockedException => $this->createResponse(
                Response::HTTP_CONFLICT,
                'Pull operation in progress',
                $exception->getMessage(),
                'profile_locked',
            ),
            default => null,
        };

        if ($response !== null) {
            $event->setResponse($response);
        }
    }

    private function createResponse(int $status, string $title, string $detail, string $code): JsonResponse
    {
        return new JsonResponse([
            'type' => 'https://tools.ietf.org/html/rfc2616#section-10',
            'title' => $title,
            'status' => $status,
            'detail' => $detail,
            'code' => $code,
        ], $status);
    }
}
