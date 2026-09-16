<?php

namespace App\HoyoverseBundle\Service;

use App\HoyoverseBundle\Exception\ConfigurationException;
use App\HoyoverseBundle\Model\ParsedCookie;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Service providing utility functions for parsing, filtering, and
 * refreshing HoYoLAB/HoYoverse authentication cookies and tokens.
 */
class HoyolabCookieUtilsService
{
    /**
     * Endpoint used to obtain new ltoken and cookie_token credentials via stoken.
     */
    private const string REFRESH_API = 'https://sg-public-api.hoyoverse.com/account/ma-passport/token/getBySToken';

    /**
     * HoYoLAB Application identifier required by the passport API.
     */
    private const string APP_ID = 'c9oqaq3s3gu8';

    /**
     * Static cryptographic salt used to generate the v1 Dynamic Secret (DS) header.
     */
    private const string APP_LOGIN_SALT = 'IZPgfb0dRPtBeLuFkdDznSZ6f4wWt6y2';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $hoyoverseLogger,
        private readonly DenormalizerInterface $denormalizer
    ) {
    }

    /**
     * Retrieves the configured logger instance.
     */
    public function getLogger(): LoggerInterface
    {
        return $this->hoyoverseLogger;
    }

    /**
     * Parses a raw delimited cookie string into a validated ParsedCookie instance.
     *
     * @param string $cookieString The raw cookie string (e.g., "key=value; other=value").
     *
     * @return ParsedCookie The populated model containing authentication tokens and identifiers.
     *
     * @throws ConfigurationException If required tokens (ltoken_v2, ltuid_v2, ltmid_v2) are missing.
     */
    public function parseCookie(string $cookieString): ParsedCookie
    {
        $cookieMap = $this->toMap($cookieString);

        $cookie = $this->denormalizer->denormalize($cookieMap, ParsedCookie::class);
        if (!$cookie->isValid()) {
            throw new ConfigurationException("No ltoken_v2, ltuid_v2, or ltmid_v2 found in cookie: $cookieString");
        }

        return $cookie;
    }

    /**
     * Filters a delimited cookie string using optional key whitelists or blacklists.
     *
     * @param string        $cookieString The raw cookie string to filter.
     * @param string        $separator    The cookie delimiter (defaults to ';').
     * @param array<string> $whitelist    If provided, only keys matching this list are retained.
     * @param array<string> $blacklist    If provided (and whitelist is empty), keys matching this list are excluded.
     *
     * @return string The reconstructed cookie string.
     */
    public function filterCookie(string $cookieString, string $separator = ';', array $whitelist = [], array $blacklist = []): string
    {
        if (!empty($whitelist)) {
            $filtered = array_filter(
                $this->toMap($cookieString, $separator),
                static fn(string $key): bool => in_array($key, $whitelist, true),
                ARRAY_FILTER_USE_KEY
            );
            return http_build_query($filtered, '', "$separator ");
        }

        if (!empty($blacklist)) {
            $filtered = array_filter(
                $this->toMap($cookieString, $separator),
                static fn(string $key): bool => !in_array($key, $blacklist, true),
                ARRAY_FILTER_USE_KEY
            );
            return http_build_query($filtered, '', "$separator ");
        }

        return $cookieString;
    }

    /**
     * Converts a delimited cookie string into an associative array of key => value pairs.
     *
     * @param string $cookieString The delimited string to parse.
     * @param string $separator    The delimiter character (defaults to ';').
     *
     * @return array<string, string> Key-value map of parsed cookie properties.
     */
    public function toMap(string $cookieString, string $separator = ';'): array
    {
        $map = [];
        $parts = explode($separator, $cookieString);

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '' || !str_contains($part, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $part, 2);
            $map[trim($key)] = trim($value);
        }

        return $map;
    }

    /**
     * Attempts to refresh authentication tokens by sending a request to the HoYoverse Passport API.
     * Updates the passed ParsedCookie instance in-place with fresh ltoken_v2 and cookie_token_v2 values.
     *
     * @param ParsedCookie $cookie The cookie model containing the refresh stoken and account IDs.
     *
     * @return bool True if tokens were successfully refreshed, false otherwise.
     */
    public function renew(ParsedCookie $cookie): bool
    {
        if (!$cookie->canAutoRenew()) {
            $this->getLogger()->warning('HoYoAuth: Refresh skipped, stoken missing for ltuid: ' . ($cookie->getLtuidV2() ?? 'unknown'));
            return false;
        }

        $cookieHeader = $this->buildCookieHeader($cookie);

        try {
            $response = $this->httpClient->request(Request::METHOD_POST, self::REFRESH_API, [
                'headers' => [
                    'ds'           => $this->generateDynamicSecret(),
                    'x-rpc-app_id' => self::APP_ID,
                    'Cookie'       => $cookieHeader,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'dst_token_types' => [2, 4], // 2 = ltoken_v2, 4 = cookie_token_v2
                ],
            ]);

            if ($response->getStatusCode() !== 200) {
                $this->getLogger()->error('HoYoAuth: HTTP request failed', [
                    'statusCode' => $response->getStatusCode(),
                    'ltuid'      => $cookie->getLtuidV2(),
                ]);
                return false;
            }

            $payload = $response->toArray(false);

            if (($payload['retcode'] ?? -1) !== 0 || !isset($payload['data']['tokens']) || !is_array($payload['data']['tokens'])) {
                $this->getLogger()->error('HoYoAuth: Invalid API response or HoYoLAB error', [
                    'retcode' => $payload['retcode'] ?? null,
                    'message' => $payload['message'] ?? 'Unknown error',
                    'ltuid'   => $cookie->getLtuidV2(),
                ]);
                return false;
            }

            $tokens = [];
            foreach ($payload['data']['tokens'] as $item) {
                if (($item['token_type'] ?? null) === 2) {
                    $tokens['ltoken_v2'] = $item['token'];
                } elseif (($item['token_type'] ?? null) === 4) {
                    $tokens['cookie_token_v2'] = $item['token'];
                }
            }

            if (empty($tokens['ltoken_v2']) || empty($tokens['cookie_token_v2'])) {
                $this->getLogger()->error('HoYoAuth: Missing tokens in API response payload', ['payload' => $payload]);
                return false;
            }

            // Persist newly acquired tokens into the model instance
            $cookie->setLtokenV2($tokens['ltoken_v2']);
            $cookie->setCookieTokenV2($tokens['cookie_token_v2']);

            // Align secondary ID aliases
            $accountId  = $cookie->getAccountIdV2() ?? $cookie->getLtuidV2();
            $accountMid = $cookie->getAccountMidV2() ?? $cookie->getLtmidV2();

            $cookie->setAccountIdV2($accountId);
            $cookie->setAccountMidV2($accountMid);

            return true;
        } catch (\Throwable $e) {
            $this->getLogger()->error('HoYoAuth: Exception thrown during refresh request: ' . $e->getMessage(), [
                'exception' => $e,
                'ltuid'     => $cookie->getLtuidV2(),
            ]);
            return false;
        }
    }

    /**
     * Builds the complete set of required cookies and backward-compatibility aliases
     * to prevent -100 "login status invalid" authentication errors.
     *
     * @param ParsedCookie $cookie The cookie model containing target account credentials.
     *
     * @return string Semicolon-separated Cookie header string.
     */
    private function buildCookieHeader(ParsedCookie $cookie): string
    {
        $parts = [];

        $stoken = $cookie->getStoken();
        $parts[] = "stoken={$stoken}";
        if (str_starts_with($stoken ?? '', 'v2_')) {
            $parts[] = "stoken_v2={$stoken}";
        }

        $uid = $cookie->getAccountIdV2() ?? $cookie->getLtuidV2();
        if ($uid !== null && $uid !== '') {
            $parts[] = "account_id={$uid}";
            $parts[] = "account_id_v2={$uid}";
            $parts[] = "ltuid={$uid}";
            $parts[] = "ltuid_v2={$uid}";
        }

        $mid = $cookie->getAccountMidV2() ?? $cookie->getLtmidV2();
        if ($mid !== null && $mid !== '') {
            $parts[] = "mid={$mid}";
            $parts[] = "account_mid_v2={$mid}";
            $parts[] = "ltmid_v2={$mid}";
        }

        return implode('; ', $parts);
    }

    /**
     * Generates a v1 Dynamic Secret (DS) header string: {timestamp},{random},{hash}.
     * Required by HoYoverse API security checks.
     *
     * @return string Formatted DS header value.
     */
    private function generateDynamicSecret(): string
    {
        $timestamp = time();
        $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $maxIndex = strlen($alphabet) - 1;

        $random = '';
        for ($i = 0; $i < 6; $i++) {
            $random .= $alphabet[random_int(0, $maxIndex)];
        }

        $hash = md5(sprintf('salt=%s&t=%d&r=%s', self::APP_LOGIN_SALT, $timestamp, $random));

        return sprintf('%d,%s,%s', $timestamp, $random, $hash);
    }

}