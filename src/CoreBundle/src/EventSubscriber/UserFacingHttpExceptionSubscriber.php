<?php

namespace App\CoreBundle\EventSubscriber;

use App\CoreBundle\Exception\UserFacingHttpExceptionInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 0)]
class UserFacingHttpExceptionSubscriber
{
    public function __invoke(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        if (!$exception instanceof UserFacingHttpExceptionInterface) {
            return;
        }

        $request = $event->getRequest();
        if (
            $request->getRequestFormat() !== 'json'
            && !str_starts_with($request->getPathInfo(), '/api/')
            && !str_contains((string) $request->headers->get('Accept'), 'json')
        ) {
            return;
        }

        $statusCode = $exception->getStatusCode();
        $response = new JsonResponse([
            'type' => 'https://tools.ietf.org/html/rfc2616#section-10',
            'title' => 'Authentication error',
            'status' => $statusCode,
            'detail' => $exception->getMessage(),
        ], $statusCode, $exception->getHeaders());

        $event->setResponse($response);
    }
}
