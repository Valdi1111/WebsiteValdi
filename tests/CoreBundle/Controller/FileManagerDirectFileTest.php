<?php

namespace App\Tests\CoreBundle\Controller;

use App\CoreBundle\EventSubscriber\SendfileContentLengthSubscriber;
use App\VideosBundle\Controller\ApiFilesController;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * FileManagerTrait::fmDirect() (video streaming, downloads), with the response steps the kernel runs after it.
 *
 * Behind Caddy (scripts/setup-server.sh) the file is handed to the web server with X-Accel-Redirect, so that a video
 * does not keep a PHP-FPM worker busy for the whole stream: Caddy sets SYMFONY_TRUST_X_SENDFILE_TYPE_HEADER and the
 * X-Sendfile-Type / X-Accel-Mapping request headers itself. Anywhere else (symfony serve, Docker without that
 * config) the X-Sendfile-Type header, which any client can send, must not be trusted, and PHP sends the file.
 */
class FileManagerDirectFileTest extends KernelTestCase
{
    private const string TRUST_ENV = 'SYMFONY_TRUST_X_SENDFILE_TYPE_HEADER';
    private const string FILE = 'a b [1]?.mp4';
    private const string CONTENT = 'not really a video';

    private string $baseFolder;

    /** @var array{server: mixed, env: mixed} */
    private array $previousTrust;

    protected function setUp(): void
    {
        $this->previousTrust = ['server' => $_SERVER[self::TRUST_ENV] ?? null, 'env' => $_ENV[self::TRUST_ENV] ?? null];
        self::resetSendfileTrust();

        $this->baseFolder = sys_get_temp_dir() . '/file-manager-direct-' . bin2hex(random_bytes(4));
        (new Filesystem())->dumpFile($this->baseFolder . '/' . self::FILE, self::CONTENT);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->baseFolder);

        parent::tearDown();

        // BinaryFileResponse keeps the trust in a static property, which would leak into the next test
        self::resetSendfileTrust();
        foreach (['server' => '_SERVER', 'env' => '_ENV'] as $key => $global) {
            if ($this->previousTrust[$key] === null) {
                unset($GLOBALS[$global][self::TRUST_ENV]);
            } else {
                $GLOBALS[$global][self::TRUST_ENV] = $this->previousTrust[$key];
            }
        }
    }

    private static function resetSendfileTrust(): void
    {
        (new \ReflectionProperty(BinaryFileResponse::class, 'trustXSendfileTypeHeader'))->setValue(null, false);
    }

    /**
     * @param array<string, string> $server
     *
     * @return array{BinaryFileResponse, string}
     */
    private function direct(array $server, bool $behindCaddy = false): array
    {
        if ($behindCaddy) {
            $_SERVER[self::TRUST_ENV] = $_ENV[self::TRUST_ENV] = '1';
        }
        // FrameworkBundle::boot() reads the trust setting
        $kernel = self::bootKernel();

        $request = Request::create('/api/files/direct', 'GET', ['id' => self::FILE], server: $server);
        $response = (new ApiFilesController($this->baseFolder))->fmDirect($request);
        self::assertInstanceOf(BinaryFileResponse::class, $response);

        // What the kernel does on kernel.response: prepare() in the ResponseListener (priority 0), then
        // SendfileContentLengthSubscriber (-1024). The whole listener chain would need a database.
        $response->prepare($request);
        static::getContainer()->get(SendfileContentLengthSubscriber::class)(
            new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response),
        );

        ob_start();
        $response->sendContent();

        return [$response, (string) ob_get_clean()];
    }

    public function testBehindCaddyTheFileIsHandedToTheWebServer(): void
    {
        [$response, $body] = $this->direct([
            'HTTP_X_SENDFILE_TYPE' => 'X-Accel-Redirect',
            'HTTP_X_ACCEL_MAPPING' => '/=/',
        ], behindCaddy: true);

        $path = realpath($this->baseFolder . '/' . self::FILE);
        // URL-encoded, so that Caddy's rewrite keeps names with spaces, "?", "#" or "%"
        self::assertSame(rawurlencode($path), $response->headers->get('X-Accel-Redirect'));
        self::assertSame('', $body, 'PHP must not send the file itself.');
        // A Content-Length without its body would make Caddy's FastCGI client abort the response
        self::assertFalse($response->headers->has('Content-Length'));
    }

    public function testClientSentSendfileTypeHeaderIsIgnoredOutsideCaddy(): void
    {
        [$response, $body] = $this->direct(['HTTP_X_SENDFILE_TYPE' => 'X-Sendfile']);

        self::assertFalse($response->headers->has('X-Sendfile'), 'The response must not reveal the path of the file.');
        self::assertSame(self::CONTENT, $body);
        self::assertSame((string) strlen(self::CONTENT), $response->headers->get('Content-Length'));
    }

    public function testRangeRequestsWorkWhenPhpSendsTheFile(): void
    {
        // Video players seek with Range requests: without Caddy, PHP answers them itself
        [$response, $body] = $this->direct(['HTTP_RANGE' => 'bytes=4-9']);

        self::assertSame(206, $response->getStatusCode());
        self::assertSame('really', $body);
        self::assertSame('bytes 4-9/' . strlen(self::CONTENT), $response->headers->get('Content-Range'));
    }
}
