<?php

namespace App\PerfectworldBundle\Service;

use App\PerfectworldBundle\Exception\PerfectworldApiException;
use App\PerfectworldBundle\Exception\PerfectworldAuthException;
use App\PerfectworldBundle\Model\Sdk\PwLoginResult;
use App\PerfectworldBundle\Model\Sdk\PwRole;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireInline;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Client of the Perfect World web SDK (https://i.pwsdk.com) used by the NTE website.
 *
 * Every call is a GET with credentials in the query string (email, login code, token), so:
 * - the HTTP client is built inline without logger nor profiler tracing (Symfony would log full URLs);
 * - no automatic retry (a retried code request would send a second email);
 * - errors are rethrown without the original exception, whose message contains the URL.
 */
class PerfectworldSdkClient
{
    public const int APP_ID = 3000001;
    public const string PACKAGE_NAME = 'com.hottagames.nte';
    public const int OS_TYPE = 6;
    public const int SDK_SERVER_VERSION = 20260702;
    public const string LANGUAGE = 'en';

    public function __construct(
        #[AutowireInline([HttpClient::class, 'create'], [[
            'base_uri' => 'https://i.pwsdk.com',
            'timeout' => 10,
            'headers' => [
                'Accept' => 'application/json, text/javascript, */*',
                'Referer' => 'https://nte.perfectworld.com/',
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36',
            ],
        ]])]
        private readonly HttpClientInterface $client,
        #[Target('perfectworld')]
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Random device id: the SDK expects a browser fingerprint, any stable 64-hex value works.
     */
    public static function generateNdid(): string
    {
        return bin2hex(random_bytes(32));
    }

    public function sendEmailCode(string $email, string $ndid): void
    {
        $this->request('/web/user/email/code/getV0.do', [
            'appId' => self::APP_ID,
            'packageName' => self::PACKAGE_NAME,
            'osType' => self::OS_TYPE,
            'emailLang' => self::LANGUAGE,
            'alertLanguage' => self::LANGUAGE,
            'ndid' => $ndid,
            'timestamp' => self::timestamp(),
            'email' => $email,
        ]);
    }

    public function loginWithEmailCode(string $email, #[\SensitiveParameter] string $code, string $ndid): PwLoginResult
    {
        $result = $this->request('/web/user/email/login/code.do', [
            'appId' => self::APP_ID,
            'packageName' => self::PACKAGE_NAME,
            'osType' => self::OS_TYPE,
            'alertLanguage' => self::LANGUAGE,
            'ndid' => $ndid,
            'timestamp' => self::timestamp(),
            'email' => $email,
            'emailCode' => $code,
            'showThirdUsers' => 1,
            'smsUsage' => 1,
            'xClientData' => json_encode(['sdkServerVersion' => self::SDK_SERVER_VERSION], JSON_THROW_ON_ERROR),
        ]);

        $uid = trim((string) ($result['uid'] ?? ''));
        $token = trim((string) ($result['token'] ?? ''));
        if ($uid === '' || $token === '') {
            throw new PerfectworldApiException('Perfect World login did not return an account token.');
        }

        return new PwLoginResult($uid, $token, isset($result['loginType']) ? (int) $result['loginType'] : null);
    }

    /**
     * Token expiry (30 days after login). Best-effort: returns null when the check fails,
     * except for an invalid token, which is rethrown.
     *
     * @throws PerfectworldAuthException
     */
    public function getTokenExpiry(string $uid, #[\SensitiveParameter] string $token, string $ndid): ?\DateTimeImmutable
    {
        try {
            $result = $this->request('/web/user/token/info.do', [
                'appId' => self::APP_ID,
                'packageName' => self::PACKAGE_NAME,
                'uid' => $uid,
                'token' => $token,
                'ndid' => $ndid,
                'timestamp' => self::timestamp(),
                'alertLanguage' => self::LANGUAGE,
                'xClientData' => json_encode([
                    'appId' => self::APP_ID,
                    'packageName' => self::PACKAGE_NAME,
                    'sdkServerVersion' => self::SDK_SERVER_VERSION,
                    'gameClientLang' => self::LANGUAGE,
                    'deviceName' => 'chrome',
                ], JSON_THROW_ON_ERROR),
            ]);
        } catch (PerfectworldAuthException $e) {
            throw $e;
        } catch (PerfectworldApiException $e) {
            $this->logger->warning('Perfect World token expiry check failed.', ['api_code' => $e->getApiCode(), 'error' => $e->getMessage()]);

            return null;
        }

        $expireTime = $result['tokenExpireTime'] ?? null;
        if (!is_numeric($expireTime) || (int) $expireTime <= 0) {
            return null;
        }

        // "@timestamp" dates are UTC: convert to the app timezone, which Doctrine DATETIME columns assume
        return (new \DateTimeImmutable('@' . (int) $expireTime))->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    }

    /**
     * @return list<PwRole>
     *
     * @throws PerfectworldAuthException when the token is invalid or expired
     */
    public function getRoles(string $uid, #[\SensitiveParameter] string $token): array
    {
        $result = $this->request('/game/getRoleList/bi.do', [
            'appId' => self::APP_ID,
            'uid' => $uid,
            'token' => $token,
            'getRoleMap' => 1,
            'alertLanguage' => self::LANGUAGE,
        ]);

        $roles = [];
        foreach ((array) ($result['roleList'] ?? []) as $entry) {
            $roleMap = is_array($entry) ? ($entry['roleMap'] ?? $entry) : null;
            $role = is_array($roleMap) ? PwRole::fromRoleMap($roleMap) : null;
            if ($role !== null) {
                $roles[] = $role;
            }
        }

        return $roles;
    }

    /**
     * @param array<string, scalar> $query
     * @return array<string, mixed> The "result" object of the response
     */
    private function request(string $endpoint, array $query): array
    {
        try {
            $response = $this->client->request('GET', $endpoint, ['query' => $query]);
            $statusCode = $response->getStatusCode();
            $data = $response->toArray(false);
        } catch (HttpClientExceptionInterface) {
            throw new PerfectworldApiException(sprintf('Perfect World service unavailable (%s).', $endpoint), null, Response::HTTP_BAD_GATEWAY);
        }

        if ($statusCode >= 400) {
            throw new PerfectworldApiException(sprintf('Perfect World request failed (%s, HTTP %d).', $endpoint, $statusCode), null, Response::HTTP_BAD_GATEWAY);
        }

        $code = isset($data['code']) && is_numeric($data['code']) ? (int) $data['code'] : -1;
        if ($code === PerfectworldAuthException::CODE_TOKEN_EXPIRED) {
            throw new PerfectworldAuthException();
        }

        if ($code !== 0) {
            $message = trim((string) ($data['message'] ?? ''));
            throw new PerfectworldApiException($message !== '' ? $message : sprintf('Perfect World request failed (%s, code %d).', $endpoint, $code), $code);
        }

        return is_array($data['result'] ?? null) ? $data['result'] : [];
    }

    private static function timestamp(): int
    {
        return (int) floor(microtime(true) * 1000);
    }
}
