<?php

namespace App\HoyoverseBundle\Service;

use App\CoreBundle\Entity\User;
use App\CoreBundle\Service\AuthSessionStore;
use App\HoyoverseBundle\Entity\HoyoverseAccount;
use App\HoyoverseBundle\Exception\HoyoverseAuthException;
use App\HoyoverseBundle\Model\Auth\HoyoverseLoginStepResult;
use App\HoyoverseBundle\Model\ParsedCookie;
use App\HoyoverseBundle\Repository\HoyoverseAccountRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class HoyoverseAuthService
{
    public const string LOGIN_ENDPOINT = '/account/ma-passport/api/appLoginByPassword';
    public const string CREATE_EMAIL_CAPTCHA_ENDPOINT = '/account/ma-verifier/api/createEmailCaptchaByActionTicket';
    public const string VERIFY_ACTION_TICKET_ENDPOINT = '/account/ma-verifier/api/verifyActionTicketPartly';

    public const string LOGIN_KEY_TYPE_1 = <<<'PEM'
-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA4PMS2JVMwBsOIrYWRluY
wEiFZL7Aphtm9z5Eu/anzJ09nB00uhW+ScrDWFECPwpQto/GlOJYCUwVM/raQpAj
/xvcjK5tNVzzK94mhk+j9RiQ+aWHaTXmOgurhxSp3YbwlRDvOgcq5yPiTz0+kSeK
ZJcGeJ95bvJ+hJ/UMP0Zx2qB5PElZmiKvfiNqVUk8A8oxLJdBB5eCpqWV6CUqDKQ
KSQP4sM0mZvQ1Sr4UcACVcYgYnCbTZMWhJTWkrNXqI8TMomekgny3y+d6NX/cFa6
6jozFIF4HCX5aW8bp8C8vq2tFvFbleQ/Q3CU56EWWKMrOcpmFtRmC18s9biZBVR/
8QIDAQAB
-----END PUBLIC KEY-----
PEM;

    private const int SESSION_TTL = 600;

    public function __construct(
        #[Target('hoyoverse.passport.client')]
        private readonly HttpClientInterface $passportClient,
        #[Target('hoyoverse.auth_session.cache')]
        private readonly CacheItemPoolInterface $authSessionCache,
        #[Target('hoyoverse')]
        private readonly LoggerInterface $logger,
        private readonly AuthSessionStore $authSessionStore,
        private readonly HoyolabUtilsService $hoyolabUtils,
        private readonly HoyolabCookieUtilsService $cookieUtils,
        private readonly HoyoverseAccountRepository $accountRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly HoyolabManagerService $hoyolabManagerService,
    ) {
    }

    public function startLogin(
        User $user,
        string $account,
        #[\SensitiveParameter]
        string $password,
        ?HoyoverseAccount $reloginAccount = null,
    ): HoyoverseLoginStepResult {
        if ($reloginAccount !== null && $reloginAccount->getUser() !== $user) {
            throw new AccessDeniedException('Cannot re-login an account belonging to another user.');
        }

        $deviceId = $reloginAccount?->getDeviceId();
        if ($deviceId === null || strlen($deviceId) !== 16) {
            $deviceId = $this->generateDeviceId();
        }

        $encryptedAccount = $this->encryptCredential($account);
        $encryptedPassword = $this->encryptCredential($password);

        $sessionData = [
            'reloginAccountId' => $reloginAccount?->getId(),
            'deviceId' => $deviceId,
            'encryptedAccount' => $encryptedAccount,
            'encryptedPassword' => $encryptedPassword,
            'pendingAction' => 'login',
            'aigisSessionId' => null,
            'captcha' => null,
            'rawVerifyHeader' => null,
            'actionTicket' => null,
        ];

        $loginSessionId = $this->authSessionStore->create($this->authSessionCache, $user, $sessionData, self::SESSION_TTL);

        return $this->executeAppLogin($user, $loginSessionId, $sessionData);
    }

    /**
     * @param array<string, mixed> $geetestPayload
     */
    public function submitCaptcha(User $user, string $loginSessionId, array $geetestPayload): HoyoverseLoginStepResult
    {
        $sessionData = $this->loadSessionOrFail($user, $loginSessionId);
        $aigisSessionId = (string) ($sessionData['aigisSessionId'] ?? '');
        if ($aigisSessionId === '') {
            throw new HoyoverseAuthException('No pending captcha challenge found for this login session.', Response::HTTP_BAD_REQUEST);
        }

        $filteredPayload = $this->filterGeetestPayload($geetestPayload);
        $aigisHeader = sprintf('%s;%s', $aigisSessionId, base64_encode((string) json_encode($filteredPayload, JSON_UNESCAPED_SLASHES)));

        $pendingAction = $sessionData['pendingAction'] ?? 'login';
        if ($pendingAction === 'resend_email') {
            return $this->resendVerificationEmail($user, $loginSessionId, $aigisHeader);
        }

        return $this->executeAppLogin($user, $loginSessionId, $sessionData, aigisHeader: $aigisHeader);
    }

    public function verifyEmail(User $user, string $loginSessionId, string $code): HoyoverseLoginStepResult
    {
        $sessionData = $this->loadSessionOrFail($user, $loginSessionId);
        $actionTicket = (string) ($sessionData['actionTicket'] ?? '');
        $rawVerifyHeader = (string) ($sessionData['rawVerifyHeader'] ?? '');

        if ($actionTicket === '' || $rawVerifyHeader === '') {
            throw new HoyoverseAuthException('No pending email verification found for this login session.', Response::HTTP_BAD_REQUEST);
        }

        $body = [
            'action_type' => 'verify_for_component',
            'action_ticket' => $actionTicket,
            'email_captcha' => trim($code),
            'verify_method' => 2,
        ];

        $bodyString = (string) json_encode($body, JSON_UNESCAPED_SLASHES);
        $headers = [
            'DS' => $this->hoyolabUtils->generateAppLoginDs($body),
            'x-rpc-device_id' => (string) $sessionData['deviceId'],
        ];

        try {
            $response = $this->passportClient->request(Request::METHOD_POST, self::VERIFY_ACTION_TICKET_ENDPOINT, [
                'headers' => $headers,
                'body' => $bodyString,
            ]);
            $payload = $response->toArray(false);
        } catch (\Throwable $e) {
            throw new HoyoverseAuthException('Failed to contact HoYoverse verification server: ' . $e->getMessage(), Response::HTTP_BAD_GATEWAY, null, $e);
        }

        $retcode = isset($payload['retcode']) ? (int) $payload['retcode'] : -1;
        if ($retcode !== 0) {
            throw HoyoverseAuthException::fromRetcode($retcode, $payload['message'] ?? null);
        }

        return $this->executeAppLogin($user, $loginSessionId, $sessionData, verifyHeader: $rawVerifyHeader);
    }

    public function resendVerificationEmail(User $user, string $loginSessionId, ?string $aigisHeader = null): HoyoverseLoginStepResult
    {
        $sessionData = $this->loadSessionOrFail($user, $loginSessionId);
        $actionTicket = (string) ($sessionData['actionTicket'] ?? '');
        if ($actionTicket === '') {
            throw new HoyoverseAuthException('No pending email verification ticket found for this login session.', Response::HTTP_BAD_REQUEST);
        }

        $body = [
            'action_type' => 'verify_for_component',
            'action_ticket' => $actionTicket,
        ];

        $bodyString = (string) json_encode($body, JSON_UNESCAPED_SLASHES);
        $headers = [
            'DS' => $this->hoyolabUtils->generateAppLoginDs($body),
            'x-rpc-device_id' => (string) $sessionData['deviceId'],
        ];
        if ($aigisHeader !== null && $aigisHeader !== '') {
            $headers['x-rpc-aigis'] = $aigisHeader;
        }

        try {
            $response = $this->passportClient->request(Request::METHOD_POST, self::CREATE_EMAIL_CAPTCHA_ENDPOINT, [
                'headers' => $headers,
                'body' => $bodyString,
            ]);
            $payload = $response->toArray(false);
        } catch (\Throwable $e) {
            throw new HoyoverseAuthException('Failed to request email verification code: ' . $e->getMessage(), Response::HTTP_BAD_GATEWAY, null, $e);
        }

        $retcode = isset($payload['retcode']) ? (int) $payload['retcode'] : -1;

        if ($retcode === -3101) {
            return $this->handleAigisCaptchaRequired($user, $loginSessionId, $sessionData, $response, 'resend_email');
        }

        if ($retcode !== 0) {
            throw HoyoverseAuthException::fromRetcode($retcode, $payload['message'] ?? null);
        }

        $sessionData['pendingAction'] = 'login';
        $this->authSessionStore->update($this->authSessionCache, $user, $loginSessionId, $sessionData, self::SESSION_TTL);

        return new HoyoverseLoginStepResult(
            status: HoyoverseLoginStepResult::STATUS_EMAIL_VERIFICATION_REQUIRED,
            loginSessionId: $loginSessionId,
            message: 'A new verification code has been sent to your email address.',
        );
    }

    /**
     * Retrieves the stored captcha configuration for rendering the GeeTest iframe.
     *
     * @return array<string, mixed>
     */
    public function getSessionCaptchaConfig(User $user, string $loginSessionId): array
    {
        $sessionData = $this->loadSessionOrFail($user, $loginSessionId);
        $captcha = $sessionData['captcha'] ?? null;

        if (!is_array($captcha) || empty($captcha)) {
            throw new HoyoverseAuthException('No active captcha challenge found for this session.', Response::HTTP_BAD_REQUEST);
        }

        return $captcha;
    }

    public function encryptCredential(#[\SensitiveParameter] string $plain): string
    {
        $encrypted = '';
        $ok = openssl_public_encrypt($plain, $encrypted, self::LOGIN_KEY_TYPE_1, OPENSSL_PKCS1_PADDING);
        if (!$ok) {
            throw new \RuntimeException('Failed to RSA-encrypt credential with HoYoverse public key.');
        }

        return base64_encode($encrypted);
    }

    /**
     * @param array<string, mixed> $sessionData
     */
    private function executeAppLogin(
        User $user,
        string $loginSessionId,
        array $sessionData,
        ?string $aigisHeader = null,
        ?string $verifyHeader = null,
    ): HoyoverseLoginStepResult {
        $body = [
            'account' => (string) $sessionData['encryptedAccount'],
            'password' => (string) $sessionData['encryptedPassword'],
        ];

        $bodyString = (string) json_encode($body, JSON_UNESCAPED_SLASHES);
        $headers = [
            'DS' => $this->hoyolabUtils->generateAppLoginDs($body),
            'x-rpc-device_id' => (string) $sessionData['deviceId'],
        ];

        if ($aigisHeader !== null && $aigisHeader !== '') {
            $headers['x-rpc-aigis'] = $aigisHeader;
        }
        if ($verifyHeader !== null && $verifyHeader !== '') {
            $headers['x-rpc-verify'] = $verifyHeader;
        }

        try {
            $response = $this->passportClient->request(Request::METHOD_POST, self::LOGIN_ENDPOINT, [
                'headers' => $headers,
                'body' => $bodyString,
            ]);
            $payload = $response->toArray(false);
        } catch (\Throwable $e) {
            throw new HoyoverseAuthException('Failed to connect to HoYoverse Passport server: ' . $e->getMessage(), Response::HTTP_BAD_GATEWAY, null, $e);
        }

        $retcode = isset($payload['retcode']) ? (int) $payload['retcode'] : -1;

        if ($retcode === -3101) {
            return $this->handleAigisCaptchaRequired($user, $loginSessionId, $sessionData, $response, 'login');
        }

        if ($retcode === -3239) {
            return $this->handleEmailVerificationRequired($user, $loginSessionId, $sessionData, $response);
        }

        if ($retcode !== 0) {
            throw HoyoverseAuthException::fromRetcode($retcode, $payload['message'] ?? null);
        }

        $stoken = (string) ($payload['data']['token']['token'] ?? '');
        $aid = (string) ($payload['data']['user_info']['aid'] ?? '');
        $mid = (string) ($payload['data']['user_info']['mid'] ?? '');

        if ($stoken === '' || $aid === '' || $mid === '') {
            throw new HoyoverseAuthException('HoYoverse login succeeded, but required stoken or user identifiers were missing from the response.');
        }

        $account = $this->finalizeAuthenticatedAccount(
            user: $user,
            stoken: $stoken,
            aid: $aid,
            mid: $mid,
            deviceId: (string) $sessionData['deviceId'],
            reloginAccountId: isset($sessionData['reloginAccountId']) ? (int) $sessionData['reloginAccountId'] : null,
        );

        $this->authSessionStore->delete($this->authSessionCache, $loginSessionId);

        return new HoyoverseLoginStepResult(
            status: HoyoverseLoginStepResult::STATUS_AUTHENTICATED,
            account: $account,
            message: 'HoYoverse account authenticated and tokens refreshed.',
        );
    }

    /**
     * @param array<string, mixed> $sessionData
     */
    private function handleAigisCaptchaRequired(
        User $user,
        string $loginSessionId,
        array $sessionData,
        ResponseInterface $response,
        string $pendingAction,
    ): HoyoverseLoginStepResult {
        $headers = $response->getHeaders(false);
        $rawAigis = $headers['x-rpc-aigis'][0] ?? '';

        if ($rawAigis === '') {
            throw new HoyoverseAuthException('Captcha required by HoYoverse (-3101), but x-rpc-aigis header was missing.');
        }

        $aigis = json_decode($rawAigis, true);
        if (!is_array($aigis) || empty($aigis['session_id'])) {
            throw new HoyoverseAuthException('Malformed x-rpc-aigis header received from HoYoverse.');
        }

        $mmtData = $aigis['data'] ?? [];
        if (is_string($mmtData)) {
            $decoded = json_decode($mmtData, true);
            $mmtData = is_array($decoded) ? $decoded : [];
        }

        $useV4 = !empty($mmtData['use_v4']);
        $version = $useV4 ? 4 : 3;

        $captchaConfig = [
            'version' => $version,
            'use_v4' => $useV4,
            'session_id' => (string) $aigis['session_id'],
            'gt' => $mmtData['gt'] ?? null,
            'captcha_id' => $mmtData['captcha_id'] ?? $mmtData['gt'] ?? null,
            'challenge' => $mmtData['challenge'] ?? null,
            'new_captcha' => $mmtData['new_captcha'] ?? true,
            'success' => $mmtData['success'] ?? 1,
            'risk_type' => $mmtData['risk_type'] ?? null,
            'api_servers' => $useV4 ? ['gcaptcha4.captchami.com'] : null,
            'api_server' => !$useV4 ? 'api-na.geetest.com' : null,
        ];

        $sessionData['aigisSessionId'] = (string) $aigis['session_id'];
        $sessionData['pendingAction'] = $pendingAction;
        $sessionData['captcha'] = $captchaConfig;

        $this->authSessionStore->update($this->authSessionCache, $user, $loginSessionId, $sessionData, self::SESSION_TTL);

        return new HoyoverseLoginStepResult(
            status: HoyoverseLoginStepResult::STATUS_CAPTCHA_REQUIRED,
            loginSessionId: $loginSessionId,
            captcha: $captchaConfig,
            message: 'Please complete the security verification.',
        );
    }

    /**
     * @param array<string, mixed> $sessionData
     */
    private function handleEmailVerificationRequired(
        User $user,
        string $loginSessionId,
        array $sessionData,
        ResponseInterface $response,
    ): HoyoverseLoginStepResult {
        $headers = $response->getHeaders(false);
        $rawVerify = $headers['x-rpc-verify'][0] ?? '';

        if ($rawVerify === '') {
            throw new HoyoverseAuthException('Email verification required by HoYoverse (-3239), but x-rpc-verify header was missing.');
        }

        $verifyObj = json_decode($rawVerify, true);
        if (!is_array($verifyObj)) {
            throw new HoyoverseAuthException('Malformed x-rpc-verify header received from HoYoverse.');
        }

        $verifyStr = $verifyObj['verify_str'] ?? [];
        if (is_string($verifyStr)) {
            $decoded = json_decode($verifyStr, true);
            $verifyStr = is_array($decoded) ? $decoded : [];
        }

        $ticket = (string) ($verifyStr['ticket'] ?? '');
        if ($ticket === '') {
            throw new HoyoverseAuthException('Email verification ticket missing in x-rpc-verify header.');
        }

        $sessionData['rawVerifyHeader'] = $rawVerify;
        $sessionData['actionTicket'] = $ticket;
        $sessionData['pendingAction'] = 'login';

        $this->authSessionStore->update($this->authSessionCache, $user, $loginSessionId, $sessionData, self::SESSION_TTL);

        return new HoyoverseLoginStepResult(
            status: HoyoverseLoginStepResult::STATUS_EMAIL_VERIFICATION_REQUIRED,
            loginSessionId: $loginSessionId,
            message: 'A verification code has been sent to your registered email address.',
        );
    }

    private function finalizeAuthenticatedAccount(
        User $user,
        string $stoken,
        string $aid,
        string $mid,
        string $deviceId,
        ?int $reloginAccountId,
    ): HoyoverseAccount {
        $parsedCookie = (new ParsedCookie())
            ->setStokenV2($stoken)
            ->setLtuidV2($aid)
            ->setLtmidV2($mid)
            ->setAccountIdV2($aid)
            ->setAccountMidV2($mid);

        if (!$this->cookieUtils->renew($parsedCookie)) {
            throw new HoyoverseAuthException('Login succeeded, but failed to exchange stoken_v2 for ltoken_v2 and cookie_token_v2.');
        }

        if ($reloginAccountId !== null) {
            $account = $this->accountRepository->find($reloginAccountId);
            if ($account === null) {
                throw new HoyoverseAuthException('Target account for re-login was not found.', Response::HTTP_NOT_FOUND);
            }
            if ($account->getUser() !== $user) {
                throw new AccessDeniedException('Cannot re-login an account belonging to another user.');
            }

            $existingUid = $account->getAccountUid();

            if ($existingUid !== null && $existingUid !== '' && $existingUid !== $aid) {
                throw new HoyoverseAuthException(
                    sprintf('The logged-in HoYoverse account (UID %s) does not match existing Account #%d (UID %s).', $aid, $account->getId(), $existingUid),
                    Response::HTTP_UNPROCESSABLE_ENTITY
                );
            }

            $account->setAccountUid($aid);
            $account->setCookie($parsedCookie->toStorageString());
            if ($account->getDeviceId() === null || $account->getDeviceId() === '') {
                $account->setDeviceId($deviceId);
            }
            $account->setLastCookieRefreshedAt(new \DateTimeImmutable());
            $account->setCookieRefreshFailed(false);
        } else {
            // Check if the user already has an account with the same accountUid
            $account = $this->accountRepository->findOneBy([
                'user' => $user,
                'accountUid' => $aid,
            ]);

            if ($account === null) {
                $account = (new HoyoverseAccount())
                    ->setUser($user)
                    ->setDeviceId($deviceId);
                $this->entityManager->persist($account);
            } elseif ($account->getDeviceId() === null || $account->getDeviceId() === '') {
                $account->setDeviceId($deviceId);
            }

            $account->setAccountUid($aid);
            $account->setCookie($parsedCookie->toStorageString());
            $account->setLastCookieRefreshedAt(new \DateTimeImmutable());
            $account->setCookieRefreshFailed(false);
        }

        $this->entityManager->flush();

        // Non-blocking auto-discovery of game profiles
        try {
            $this->hoyolabManagerService->updateCachedGameProfilesByGameRecords($account);
            $this->entityManager->flush();
        } catch (\Throwable $e) {
            $this->logger->warning('HoYoAuth: Auto-sync of game profiles after login failed: ' . $e->getMessage(), [
                'accountId' => $account->getId(),
                'exception' => $e,
            ]);
        }

        return $account;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadSessionOrFail(User $user, string $loginSessionId): array
    {
        $session = $this->authSessionStore->get($this->authSessionCache, $user, $loginSessionId);
        if ($session === null) {
            throw new HoyoverseAuthException('Login session expired or invalid. Please start again.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $session;
    }

    /**
     * Filters out session_id/check_id and retains only GeeTest v3/v4 validation fields.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function filterGeetestPayload(array $payload): array
    {
        $allowedKeys = [
            // GeeTest v3
            'geetest_challenge',
            'geetest_validate',
            'geetest_seccode',
            // GeeTest v4
            'captcha_id',
            'lot_number',
            'pass_token',
            'gen_time',
            'captcha_output',
        ];

        $filtered = [];
        foreach ($allowedKeys as $key) {
            if (isset($payload[$key]) && is_scalar($payload[$key])) {
                $filtered[$key] = (string) $payload[$key];
            }
        }

        if (empty($filtered)) {
            throw new HoyoverseAuthException('Invalid or empty captcha validation payload.', Response::HTTP_BAD_REQUEST);
        }

        return $filtered;
    }

    private function generateDeviceId(): string
    {
        $alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
        $maxIndex = strlen($alphabet) - 1;
        $deviceId = '';
        for ($i = 0; $i < 16; $i++) {
            $deviceId .= $alphabet[random_int(0, $maxIndex)];
        }

        return $deviceId;
    }
}
