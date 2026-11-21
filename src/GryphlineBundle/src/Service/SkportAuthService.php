<?php

namespace App\GryphlineBundle\Service;

use App\GryphlineBundle\Entity\GryphlineAccount;
use App\GryphlineBundle\Exception\SkportAuthException;
use App\GryphlineBundle\Exception\SkportUnavailableException;
use App\GryphlineBundle\Model\SkportAccountInfo;
use App\GryphlineBundle\Model\SkportSessionCredentials;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class SkportAuthService
{
    public const string APP_CODE = '6eb76d4e13aa36e6';
    public const string ENDFIELD_APP_CODE = '3dacefa138426cfe';
    public const string BINDING_BASE_URL = 'https://binding-api-account-prod.gryphline.com';
    public const int SESSION_TTL_SECONDS = 2700; // 45 minutes

    public function __construct(
        #[Target('gryphline.as.client')]
        private readonly HttpClientInterface $asClient,
        #[Target('gryphline.skport.client')]
        private readonly HttpClientInterface $skportClient,
        private readonly HttpClientInterface $httpClient,
        private readonly EntityManagerInterface $entityManager,
        #[Target('gryphline')]
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getOrRefreshCredentials(GryphlineAccount $account, bool $force = false): SkportSessionCredentials
    {
        // Return existing credentials if force refresh is not requested and the session is still valid
        if (!$force && $account->isSessionValid()) {
            return new SkportSessionCredentials(
                cred: $account->getSessionCred(),
                salt: $account->getSessionSalt(),
                userId: '',
                hgId: $account->getAccountUid(),
                expiresAt: \DateTimeImmutable::createFromInterface($account->getSessionExpiresAt())
            );
        }

        $token = rawurldecode(trim((string) $account->getAccountToken()));
        if (empty($token)) {
            $this->flagTokenRefreshFailed($account);
            throw new SkportAuthException(sprintf('GryphlineAccount ID %d does not have an accountToken configured.', $account->getId()));
        }

        try {
            $credentials = $this->performOAuthFlow($token);

            // The token must still belong to the stored Gryphline account: never move an account to another identity.
            // Checked before touching the session fields, because the catch below flushes the entity.
            $returnedUid = self::normalizeUid($credentials->getHgId());
            $storedUid = self::normalizeUid($account->getAccountUid());
            if ($returnedUid !== null && $storedUid !== null && $returnedUid !== $storedUid) {
                throw new SkportAuthException(sprintf(
                    'The token of Account #%d belongs to another Gryphline account (UID %s instead of %s). Log in again with the right account.',
                    $account->getId(),
                    $returnedUid,
                    $storedUid,
                ));
            }

            if ($returnedUid !== null) {
                $account->setAccountUid($returnedUid);
            }
            $account->setSessionCred($credentials->getCred());
            $account->setSessionSalt($credentials->getSalt());
            $account->setSessionExpiresAt($credentials->getExpiresAt());
            $account->setLastTokenRefreshedAt(new \DateTimeImmutable());
            $account->setTokenRefreshFailed(false);

            $this->entityManager->flush();

            return $credentials;
        } catch (\Throwable $e) {
            // Network errors and Gryphline outages say nothing about the token: keep the account state, retry later
            $unavailable = self::asUnavailable($e);
            if ($unavailable !== null) {
                $this->logger->warning(sprintf('Credentials refresh skipped for GryphlineAccount ID %d: %s', $account->getId(), $unavailable->getMessage()));

                throw $unavailable;
            }

            $this->flagTokenRefreshFailed($account);

            $this->logger->error(sprintf(
                'Failed to refresh credentials for GryphlineAccount ID %d: %s',
                $account->getId(),
                $e->getMessage()
            ), ['exception' => $e]);

            if ($e instanceof SkportAuthException) {
                throw $e;
            }

            throw new SkportAuthException('Unexpected error during OAuth authentication: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Step 1 of the OAuth flow: verifies the account token and returns the Gryphline account it belongs to.
     * Also used on its own to identify a pasted token before it is saved.
     *
     * @throws SkportUnavailableException when Gryphline cannot be reached
     * @throws SkportAuthException when the token is invalid or expired
     */
    public function fetchAccountInfo(string $accountToken): SkportAccountInfo
    {
        $accountToken = rawurldecode(trim($accountToken));

        $infoUrl = '/user/info/v1/basic?token=' . urlencode($accountToken);
        try {
            $infoResponse = $this->asClient->request(Request::METHOD_GET, $infoUrl);
            self::assertAvailable($infoResponse);

            if ($infoResponse->getStatusCode() !== Response::HTTP_OK) {
                throw new SkportAuthException('OAuth Step 1 HTTP error: ' . $infoResponse->getStatusCode())
                    ->setApiStatusCode($infoResponse->getStatusCode());
            }

            $infoData = $infoResponse->toArray(false);
        } catch (TransportExceptionInterface|DecodingExceptionInterface) {
            // The transport error quotes the URL, which contains the token: never pass it on
            throw new SkportUnavailableException();
        }
        if (($infoData['status'] ?? -1) !== 0) {
            $msg = $infoData['msg'] ?? ('status ' . ($infoData['status'] ?? 'unknown'));
            throw new SkportAuthException('OAuth Step 1 failed: ' . $msg)
                ->setApiCode($infoData['status'] ?? null)
                ->setResponseBody($infoData);
        }

        $nickname = $infoData['data']['nickname'] ?? null;

        return new SkportAccountInfo(
            hgId: self::normalizeUid($infoData['data']['hgId'] ?? null),
            nickname: is_scalar($nickname) && (string) $nickname !== '' ? (string) $nickname : null,
        );
    }

    /**
     * HTTP 5xx/429 from Gryphline or SKPort: a temporary outage, not a rejected token.
     */
    private static function assertAvailable(ResponseInterface $response): void
    {
        $statusCode = $response->getStatusCode();
        if (SkportUnavailableException::isUnavailableStatus($statusCode)) {
            throw new SkportUnavailableException(sprintf('Gryphline is unavailable (HTTP %d). Please try again later.', $statusCode));
        }
    }

    private static function asUnavailable(\Throwable $e): ?SkportUnavailableException
    {
        return match (true) {
            $e instanceof SkportUnavailableException => $e,
            $e instanceof TransportExceptionInterface, $e instanceof DecodingExceptionInterface => new SkportUnavailableException(),
            default => null,
        };
    }

    private function flagTokenRefreshFailed(GryphlineAccount $account): void
    {
        $account->setTokenRefreshFailed(true);
        $this->entityManager->flush();
    }

    /**
     * hgId may come as a JSON number or string: compare and store it as a trimmed string.
     */
    public static function normalizeUid(mixed $uid): ?string
    {
        if (!is_scalar($uid)) {
            return null;
        }
        $uid = trim((string) $uid);

        return $uid !== '' ? $uid : null;
    }

    public function performOAuthFlow(string $accountToken): SkportSessionCredentials
    {
        $accountToken = rawurldecode(trim($accountToken));

        // Step 1: Verify Account Info
        $accountInfo = $this->fetchAccountInfo($accountToken);

        // Step 2: Request OAuth Grant Code
        $grantResponse = $this->asClient->request(Request::METHOD_POST, '/user/oauth2/v2/grant', [
            'json' => [
                'token' => $accountToken,
                'appCode' => self::APP_CODE,
                'type' => 0,
            ],
        ]);

        self::assertAvailable($grantResponse);
        if ($grantResponse->getStatusCode() !== Response::HTTP_OK) {
            throw new SkportAuthException('OAuth Step 2 HTTP error: ' . $grantResponse->getStatusCode())
                ->setApiStatusCode($grantResponse->getStatusCode());
        }

        $grantData = $grantResponse->toArray(false);
        if (($grantData['status'] ?? -1) !== 0 || empty($grantData['data']['code'])) {
            $msg = $grantData['msg'] ?? ('status ' . ($grantData['status'] ?? 'unknown'));
            throw new SkportAuthException('OAuth Step 2 failed: ' . $msg)
                ->setApiCode($grantData['status'] ?? null)
                ->setResponseBody($grantData);
        }

        $code = $grantData['data']['code'];

        // Step 3: Generate SKPort session credentials by authorization code
        $credResponse = $this->skportClient->request(Request::METHOD_POST, '/web/v1/user/auth/generate_cred_by_code', [
            'headers' => [
                'platform' => '3',
            ],
            'json' => [
                'code' => $code,
                'kind' => 1,
            ],
        ]);

        self::assertAvailable($credResponse);
        if ($credResponse->getStatusCode() !== Response::HTTP_OK) {
            throw new SkportAuthException('OAuth Step 3 HTTP error: ' . $credResponse->getStatusCode())
                ->setApiStatusCode($credResponse->getStatusCode());
        }

        $credData = $credResponse->toArray(false);
        if (($credData['code'] ?? -1) !== 0 || empty($credData['data']['cred'])) {
            $msg = $credData['message'] ?? ('code ' . ($credData['code'] ?? 'unknown'));
            throw new SkportAuthException('OAuth Step 3 failed: ' . $msg)
                ->setApiCode($credData['code'] ?? null)
                ->setResponseBody($credData);
        }

        $cred = $credData['data']['cred'];
        $salt = $credData['data']['token'];
        $userId = (string) ($credData['data']['userId'] ?? '');
        $expiresAt = new \DateTimeImmutable()->modify('+' . self::SESSION_TTL_SECONDS . ' seconds');

        return new SkportSessionCredentials(
            cred: $cred,
            salt: $salt,
            userId: $userId,
            hgId: $accountInfo->hgId,
            nickname: $accountInfo->nickname,
            expiresAt: $expiresAt
        );
    }

    /**
     * Obtains the U8 token required for Endfield webview record APIs.
     *
     * @throws SkportAuthException
     */
    public function getEndfieldU8Token(GryphlineAccount $account, ?string $roleId = null): string
    {
        // An account flagged by a failed renewal renews its session first. That verifies the token and the account
        // identity (a token of another Gryphline account would import another player's pulls) and, on success,
        // clears the flag; on failure it throws with the account still flagged.
        if ($account->isTokenRefreshFailed()) {
            $this->getOrRefreshCredentials($account, force: true);
        }

        $token = rawurldecode(trim((string) $account->getAccountToken()));
        if (empty($token)) {
            $this->flagTokenRefreshFailed($account);
            throw new SkportAuthException(sprintf('GryphlineAccount ID %d does not have an accountToken configured.', $account->getId()));
        }

        // Step 1: Request OAuth Grant Token for Endfield (type: 1 returns 'token' inside data)
        $grantResponse = $this->asClient->request(Request::METHOD_POST, '/user/oauth2/v2/grant', [
            'json' => [
                'token' => $token,
                'appCode' => self::ENDFIELD_APP_CODE,
                'type' => 1,
            ],
        ]);

        try {
            self::assertAvailable($grantResponse);
            $grantData = $grantResponse->toArray(false);
        } catch (TransportExceptionInterface|DecodingExceptionInterface) {
            throw new SkportUnavailableException();
        }
        $oauthToken = $grantData['data']['token'] ?? '';
        if (($grantData['status'] ?? -1) !== 0 || $oauthToken === '') {
            // The account token was rejected: flag the account as a failed session renewal would
            $this->flagTokenRefreshFailed($account);
            throw new SkportAuthException('Endfield OAuth grant failed: ' . ($grantData['msg'] ?? 'no token'));
        }

        // Step 2: Fetch Binding List to get the Endfield binding UID
        $bindingResponse = $this->httpClient->request(Request::METHOD_GET, self::BINDING_BASE_URL . '/account/binding/v1/binding_list', [
            'query' => [
                'token' => $oauthToken,
                'appCode' => 'endfield',
            ],
            'headers' => [
                'token' => $oauthToken,
                'Accept' => 'application/json',
            ],
        ]);

        $bindingData = $bindingResponse->toArray(false);
        if (($bindingData['status'] ?? -1) !== 0 || empty($bindingData['data']['list'])) {
            throw new SkportAuthException('Endfield binding list request failed: ' . ($bindingData['msg'] ?? 'empty list'));
        }

        $endfieldUid = null;
        foreach ($bindingData['data']['list'] as $appItem) {
            if (($appItem['appCode'] ?? '') !== 'endfield' && ($appItem['appCode'] ?? '') !== self::ENDFIELD_APP_CODE) {
                continue;
            }

            foreach ($appItem['bindingList'] ?? [] as $binding) {
                if ($roleId !== null && $roleId !== '') {
                    foreach ($binding['roles'] ?? [] as $role) {
                        if ((string) ($role['roleId'] ?? '') === $roleId) {
                            $endfieldUid = (string) ($binding['uid'] ?? '');
                            break 3;
                        }
                    }
                }

                if ($endfieldUid === null && !empty($binding['uid'])) {
                    $endfieldUid = (string) $binding['uid'];
                }
            }
        }

        if (empty($endfieldUid)) {
            throw new SkportAuthException('Could not find Endfield UID in binding list.');
        }

        // Step 3: Get U8 Token by UID
        $u8Response = $this->httpClient->request(Request::METHOD_POST, self::BINDING_BASE_URL . '/account/binding/v1/u8_token_by_uid', [
            'headers' => [
                'token' => $oauthToken,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'token' => $oauthToken,
                'uid' => $endfieldUid,
            ],
        ]);

        $u8Data = $u8Response->toArray(false);
        if (($u8Data['status'] ?? -1) !== 0 || empty($u8Data['data']['token'])) {
            throw new SkportAuthException('Failed to retrieve Endfield u8_token: ' . ($u8Data['msg'] ?? 'unknown error'));
        }

        return (string) $u8Data['data']['token'];
    }
}
