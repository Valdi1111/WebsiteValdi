<?php

namespace App\Tests\CoreBundle\Service;

use App\CoreBundle\EventSubscriber\UserFacingHttpExceptionSubscriber;
use App\CoreBundle\Exception\UserFacingHttpExceptionInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

class CoreAuthComponentsTest extends TestCase
{
    public function testUserFacingHttpExceptionSubscriberFormatsRfc7807Detail(): void
    {
        $exception = new class('Specific authentication failure message') extends \RuntimeException implements UserFacingHttpExceptionInterface {
            public function getStatusCode(): int
            {
                return Response::HTTP_UNPROCESSABLE_ENTITY;
            }

            public function getHeaders(): array
            {
                return [];
            }
        };

        $kernel = $this->createStub(HttpKernelInterface::class);
        $request = Request::create('/api/accounts/login/start', 'POST');
        $request->setRequestFormat('json');

        $event = new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $exception);
        $subscriber = new UserFacingHttpExceptionSubscriber();
        $subscriber($event);

        $response = $event->getResponse();
        $this->assertNotNull($response);
        $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());

        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('Specific authentication failure message', $data['detail']);
        $this->assertSame(422, $data['status']);
    }
}
