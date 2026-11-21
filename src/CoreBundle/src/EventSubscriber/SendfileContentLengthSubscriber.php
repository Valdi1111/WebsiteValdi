<?php

namespace App\CoreBundle\EventSubscriber;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Files handed to the web server (X-Accel-Redirect, see scripts/setup-server.sh) leave PHP without a body, but
 * BinaryFileResponse::prepare() keeps the file size in Content-Length. Caddy's FastCGI client then waits for
 * bytes that never come and aborts the response, so the header goes: Caddy sets the right one when it sends the file.
 *
 * Runs after the ResponseListener (priority 0), which is where prepare() happens.
 */
#[AsEventListener(event: KernelEvents::RESPONSE, priority: -1024)]
class SendfileContentLengthSubscriber
{
    private const array SENDFILE_HEADERS = ['X-Accel-Redirect', 'X-Sendfile', 'X-Lighttpd-Send-File'];

    public function __invoke(ResponseEvent $event): void
    {
        $response = $event->getResponse();
        if (!$response instanceof BinaryFileResponse) {
            return;
        }

        foreach (self::SENDFILE_HEADERS as $header) {
            if ($response->headers->has($header)) {
                $response->headers->remove('Content-Length');

                return;
            }
        }
    }
}
