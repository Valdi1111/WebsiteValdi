<?php

namespace App\GryphlineBundle\Service;

use App\GryphlineBundle\Entity\GryphlineAccount;
use App\GryphlineBundle\Exception\SkportAuthException;
use App\GryphlineBundle\Model\SkportSessionCredentials;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\HttpClientInterface;

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
            $account->setTokenRefreshFailed(true);
            $this->entityManager->flush();
            throw new SkportAuthException(sprintf('GryphlineAccount ID %d does not have an accountToken configured.', $account->getId()));
        }

        try {
            $credentials = $this->performOAuthFlow($token);

            if ($credentials->getHgId() !== null && $credentials->getHgId() !== '') {
                $account->setAccountUid((string) $credentials->getHgId());
            }
            $account->setSessionCred($credentials->getCred());
            $account->setSessionSalt($credentials->getSalt());
            $account->setSessionExpiresAt($credentials->getExpiresAt());
            $account->setLastTokenRefreshedAt(new \DateTimeImmutable());
            $account->setTokenRefreshFailed(false);

            $this->entityManager->flush();

            return $credentials;
        } catch (\Throwable $e) {
            $account->setTokenRefreshFailed(true);
            $this->entityManager->flush();

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

    public function performOAuthFlow(string $accountToken): SkportSessionCredentials
    {
        $accountToken = rawurldecode(trim($accountToken));

        // Step 1: Verify Account Info
        $infoUrl = '/user/info/v1/basic?token=' . urlencode($accountToken);
        $infoResponse = $this->asClient->request(Request::METHOD_GET, $infoUrl);

        if ($infoResponse->getStatusCode() !== Response::HTTP_OK) {
            throw new SkportAuthException('OAuth Step 1 HTTP error: ' . $infoResponse->getStatusCode())
                ->setApiStatusCode($infoResponse->getStatusCode());
        }

        $infoData = $infoResponse->toArray(false);
        if (($infoData['status'] ?? -1) !== 0) {
            $msg = $infoData['msg'] ?? ('status ' . ($infoData['status'] ?? 'unknown'));
            throw new SkportAuthException('OAuth Step 1 failed: ' . $msg)
                ->setApiCode($infoData['status'] ?? null)
                ->setResponseBody($infoData);
        }

        $hgId = $infoData['data']['hgId'] ?? null;
        $nickname = $infoData['data']['nickname'] ?? null;

        // Step 2: Request OAuth Grant Code
        $grantResponse = $this->asClient->request(Request::METHOD_POST, '/user/oauth2/v2/grant', [
            'json' => [
                'token' => $accountToken,
                'appCode' => self::APP_CODE,
                'type' => 0,
            ],
        ]);

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
            hgId: $hgId,
            nickname: $nickname,
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
        $token = rawurldecode(trim((string) $account->getAccountToken()));
        if (empty($token)) {
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

        $grantData = $grantResponse->toArray(false);
        $oauthToken = $grantData['data']['token'] ?? '';
        if (($grantData['status'] ?? -1) !== 0 || $oauthToken === '') {
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
