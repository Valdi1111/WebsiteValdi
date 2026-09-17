<?php

namespace App\HoyoverseBundle\Model\Game;

use App\HoyoverseBundle\Exception\HoyolabException;
use App\HoyoverseBundle\Model\ParsedCookie;
use App\HoyoverseBundle\Model\RuntimeAccountData;
use App\HoyoverseBundle\Service\HoyolabCookieUtilsService;
use App\HoyoverseBundle\Service\HoyolabUtilsService;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Service\Attribute\Required;

trait HoyolabTrait
{
    private ?HttpClientInterface $hoyolabClient = null;
    private ?HoyolabCookieUtilsService $cookieUtils = null;
    private ?HoyolabUtilsService $hoyolabUtils = null;
    private ?DenormalizerInterface $denormalizer = null;
    private ?ObjectMapperInterface $objectMapper = null;

    public function __construct(
        private readonly LoggerInterface $hoyoverseLogger
    )
    {
    }

    public function getLogger(): LoggerInterface
    {
        return $this->hoyoverseLogger;
    }

    public function getHoyolabClient(): ?HttpClientInterface
    {
        return $this->hoyolabClient;
    }

    #[Required]
    public function setHoyolabClient(HttpClientInterface $hoyoverseHoyolabClient): void
    {
        $this->hoyolabClient = $hoyoverseHoyolabClient;
    }

    public function getCookieUtils(): ?HoyolabCookieUtilsService
    {
        return $this->cookieUtils;
    }

    #[Required]
    public function setCookieUtils(HoyolabCookieUtilsService $cookieUtils): void
    {
        $this->cookieUtils = $cookieUtils;
    }

    public function getHoyolabUtils(): ?HoyolabUtilsService
    {
        return $this->hoyolabUtils;
    }

    #[Required]
    public function setHoyolabUtils(HoyolabUtilsService $utils): void
    {
        $this->hoyolabUtils = $utils;
    }

    public function getDenormalizer(): ?DenormalizerInterface
    {
        return $this->denormalizer;
    }

    #[Required]
    public function setDenormalizer(DenormalizerInterface $denormalizer): void
    {
        $this->denormalizer = $denormalizer;
    }

    public function getObjectMapper(): ?ObjectMapperInterface
    {
        return $this->objectMapper;
    }

    #[Required]
    public function setObjectMapper(?ObjectMapperInterface $objectMapper): void
    {
        $this->objectMapper = $objectMapper;
    }

    /**
     * Executes an HTTP request, validates HTTP status and API retcodes, and attaches context to logs.
     *
     * @template TException of HoyolabException
     *
     * @param class-string<TException> $exceptionClass
     * @param array<string, mixed>     $context Context data for Monolog (uid, region, etc.)
     * @param list<int>                $allowedRetcodes Allowed API retcodes in addition to 0
     * @return array<string, mixed>
     *
     * @throws TException
     */
    protected function executeRequest(
        HttpClientInterface $client,
        string $method,
        string $url,
        array $options,
        string $exceptionClass,
        array $context = [],
        array $allowedRetcodes = [0]
    ): array {
        $logContext = array_merge(['url' => $url, 'method' => $method], $context);

        try {
            $response = $client->request($method, $url, $options);
            $statusCode = $response->getStatusCode();
            $body = $response->toArray(false);

            if ($statusCode !== Response::HTTP_OK) {
                $this->getLogger()->error('Failed HTTP response from API', array_merge($logContext, [
                    'status' => $statusCode,
                    'body' => $body,
                ]));

                throw new $exceptionClass("Failed request, HTTP status {$statusCode}")
                    ->setHoyolabStatusCode($statusCode)
                    ->setHoyolabBody($body);
            }

            if (array_key_exists('retcode', $body)) {
                $retcode = $body['retcode'];
                if (!in_array($retcode, $allowedRetcodes, true)) {
                    $msg = $body['message'] ?? 'Unknown error';

                    $this->getLogger()->error('API returned non-zero retcode', array_merge($logContext, [
                        'retcode' => $retcode,
                        'message' => $msg,
                        'body' => $body,
                    ]));

                    throw new $exceptionClass("API returned error retcode [{$retcode}]: {$msg}")
                        ->setHoyolabRetcode($retcode)
                        ->setHoyolabMessage($msg)
                        ->setHoyolabBody($body);
                }
            }

            return $body;

        } catch (HttpClientExceptionInterface $e) {
            $this->getLogger()->error("Network exception during API request: {$e->getMessage()}", array_merge($logContext, [
                'exception' => $e,
            ]));

            throw (new $exceptionClass("Network exception: {$e->getMessage()}", 0, $e));
        }
    }

    /**
     * Dedicated wrapper for HoYoverse endpoints: handles cookies, query params, and logging context.
     *
     * @template TException of HoyolabException
     *
     * @param RuntimeAccountData|ParsedCookie $auth
     * @param class-string<TException>        $exceptionClass
     * @param list<int>                       $allowedRetcodes
     * @return array<string, mixed>
     *
     * @throws TException
     */
    protected function requestHoyolab(
        string $method,
        string $url,
        RuntimeAccountData|ParsedCookie $auth,
        array $query,
        string $exceptionClass,
        array $extraHeaders = [],
        array $allowedRetcodes = [0]
    ): array {
        if ($auth instanceof RuntimeAccountData) {
            $profile = $auth->getGameProfile();
            $cookie = (string) $auth->getParsedCookie();
            $context = [
                'uid'      => $profile->getGameUid(),
                'region'   => $profile->getRegion(),
                'nickname' => $profile->getNickname(),
            ];
        } else {
            $cookie = (string) $auth;
            $context = [
                'ltuid' => $auth->getLtuidV2(),
            ];
        }

        $headers = array_merge([
            'Cookie' => $cookie,
        ], $extraHeaders);

        return $this->executeRequest(
            client: $this->getHoyolabClient(),
            method: $method,
            url: $url,
            options: [
                'query'   => $query,
                'headers' => $headers,
            ],
            exceptionClass: $exceptionClass,
            context: $context,
            allowedRetcodes: $allowedRetcodes
        );
    }

}