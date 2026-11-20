<?php

namespace App\GryphlineBundle\Service;

use App\CoreBundle\Entity\User;
use App\GamesBundle\Service\AuthSessionStore;
use App\GryphlineBundle\Entity\GryphlineAccount;
use App\GryphlineBundle\Exception\SkportAuthException;
use App\GryphlineBundle\Model\Auth\GryphlineLoginStepResult;
use App\GryphlineBundle\Repository\GryphlineAccountRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class GryphlineAuthService
{
    public const string LOGIN_ENDPOINT = '/user/auth/v1/token_by_email_password';
    private const int SESSION_TTL = 600;

    public function __construct(
        #[Target('gryphline.as.client')]
        private readonly HttpClientInterface $asClient,
        #[Target('gryphline.auth_session.cache')]
        private readonly CacheItemPoolInterface $authSessionCache,
        #[Target('gryphline')]
        private readonly LoggerInterface $logger,
        private readonly AuthSessionStore $authSessionStore,
        private readonly SkportAuthService $skportAuthService,
        private readonly SkportManagerService $skportManagerService,
        private readonly GryphlineAccountRepository $accountRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function startLogin(
        User $user,
        string $email,
        #[\SensitiveParameter]
        string $password,
        ?GryphlineAccount $reloginAccount = null,
    ): GryphlineLoginStepResult {
        if ($reloginAccount !== null && $reloginAccount->getUser() !== $user) {
            throw new AccessDeniedException('Cannot re-login an account belonging to another user.');
        }

        $deviceId = bin2hex(random_bytes(16));
        $payload = $this->executeEmailPasswordLogin($email, $password, $deviceId);
        $status = isset($payload['status']) ? (int) $payload['status'] : -1;

        if ($status === 0) {
            $token = (string) ($payload['data']['token'] ?? '');
            if ($token === '') {
                throw new SkportAuthException('Gryphline login succeeded, but no account token was returned.');
            }

            $hgId = isset($payload['data']['hgId']) ? (string) $payload['data']['hgId'] : null;
            $account = $this->finalizeAuthenticatedAccount($user, $token, $reloginAccount, $hgId);

            return new GryphlineLoginStepResult(
                status: GryphlineLoginStepResult::STATUS_AUTHENTICATED,
                account: $account,
                message: 'Gryphline account authenticated and session credentials generated.',
            );
        }

        if ($status === 1) {
            $rawCaptcha = $payload['data']['captcha'] ?? [];
            $geetestId = (string) ($rawCaptcha['geetestId'] ?? $rawCaptcha['captcha_id'] ?? '');
            $challenge = (string) ($rawCaptcha['challenge'] ?? '');
            $riskType = $rawCaptcha['riskType'] ?? $rawCaptcha['risk_type'] ?? null;

            if ($geetestId === '') {
                throw new SkportAuthException('Captcha required by Gryphline, but geetestId was missing from response.');
            }

            $captchaConfig = [
                'version' => 4,
                'use_v4' => true,
                'captcha_id' => $geetestId,
                'risk_type' => $riskType,
            ];

            $sessionData = [
                'reloginAccountId' => $reloginAccount?->getId(),
                'email' => $email,
                'encryptedPassword' => $this->authSessionStore->encryptSecret($password),
                'deviceId' => $deviceId,
                'challenge' => $challenge,
                'captcha' => $captchaConfig,
            ];

            $loginSessionId = $this->authSessionStore->create($this->authSessionCache, $user, $sessionData, self::SESSION_TTL);

            return new GryphlineLoginStepResult(
                status: GryphlineLoginStepResult::STATUS_CAPTCHA_REQUIRED,
                loginSessionId: $loginSessionId,
                captcha: $captchaConfig,
                message: 'Please complete the security verification.',
            );
        }

        throw SkportAuthException::fromApiStatus($status, $payload['msg'] ?? null);
    }

    /**
     * @param array<string, mixed> $geetestPayload
     */
    public function submitCaptcha(User $user, string $loginSessionId, array $geetestPayload): GryphlineLoginStepResult
    {
        $sessionData = $this->loadSessionOrFail($user, $loginSessionId);
        $password = $this->authSessionStore->decryptSecret((string) ($sessionData['encryptedPassword'] ?? ''));
        $email = (string) ($sessionData['email'] ?? '');
        $deviceId = (string) ($sessionData['deviceId'] ?? bin2hex(random_bytes(16)));
        $challenge = (string) ($sessionData['challenge'] ?? '');

        $captchaData = $this->buildCaptchaPayload($geetestPayload, $challenge);
        $payload = $this->executeEmailPasswordLogin($email, $password, $deviceId, $captchaData);
        $status = isset($payload['status']) ? (int) $payload['status'] : -1;

        if ($status === 0) {
            $token = (string) ($payload['data']['token'] ?? '');
            if ($token === '') {
                $this->authSessionStore->delete($this->authSessionCache, $loginSessionId);
                throw new SkportAuthException('Gryphline login succeeded, but no account token was returned.');
            }

            $reloginAccount = null;
            if (!empty($sessionData['reloginAccountId'])) {
                $reloginAccount = $this->accountRepository->find((int) $sessionData['reloginAccountId']);
                if ($reloginAccount === null) {
                    $this->authSessionStore->delete($this->authSessionCache, $loginSessionId);
                    throw new SkportAuthException('Target account for re-login was not found.', 0, null, Response::HTTP_NOT_FOUND);
                }
                if ($reloginAccount->getUser() !== $user) {
                    $this->authSessionStore->delete($this->authSessionCache, $loginSessionId);
                    throw new AccessDeniedException('Cannot re-login an account belonging to another user.');
                }
            }

            $hgId = isset($payload['data']['hgId']) ? (string) $payload['data']['hgId'] : null;
            $account = $this->finalizeAuthenticatedAccount($user, $token, $reloginAccount, $hgId);
            $this->authSessionStore->delete($this->authSessionCache, $loginSessionId);

            return new GryphlineLoginStepResult(
                status: GryphlineLoginStepResult::STATUS_AUTHENTICATED,
                account: $account,
                message: 'Gryphline account authenticated and session credentials generated.',
            );
        }

        if ($status === 1) {
            $rawCaptcha = $payload['data']['captcha'] ?? [];
            $geetestId = (string) ($rawCaptcha['geetestId'] ?? $rawCaptcha['captcha_id'] ?? '');
            $newChallenge = (string) ($rawCaptcha['challenge'] ?? '');
            $riskType = $rawCaptcha['riskType'] ?? $rawCaptcha['risk_type'] ?? null;

            $captchaConfig = [
                'version' => 4,
                'use_v4' => true,
                'captcha_id' => $geetestId,
                'risk_type' => $riskType,
            ];

            $sessionData['challenge'] = $newChallenge;
            $sessionData['captcha'] = $captchaConfig;
            $this->authSessionStore->update($this->authSessionCache, $user, $loginSessionId, $sessionData, self::SESSION_TTL);

            return new GryphlineLoginStepResult(
                status: GryphlineLoginStepResult::STATUS_CAPTCHA_REQUIRED,
                loginSessionId: $loginSessionId,
                captcha: $captchaConfig,
                message: 'Please complete the security verification.',
            );
        }

        $this->authSessionStore->delete($this->authSessionCache, $loginSessionId);

        throw SkportAuthException::fromApiStatus($status, $payload['msg'] ?? null);
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
            throw new SkportAuthException('No active captcha challenge found for this session.', 0, null, Response::HTTP_BAD_REQUEST);
        }

        return $captcha;
    }

    /**
     * Finalizes a GryphlineAccount (new or relogin) with a freshly acquired accountToken,
     * validates and populates OAuth session credentials, and performs non-blocking profile sync.
     */
    public function finalizeAuthenticatedAccount(
        User $user,
        string $accountToken,
        ?GryphlineAccount $reloginAccount = null,
        ?string $hgId = null,
    ): GryphlineAccount {
        $normalizedHgId = $hgId !== null && trim($hgId) !== '' ? trim($hgId) : null;

        if ($reloginAccount !== null) {
            if ($reloginAccount->getUser() !== $user) {
                throw new AccessDeniedException('Cannot re-login an account belonging to another user.');
            }
            $existingUid = $reloginAccount->getAccountUid();
            if ($existingUid !== null && $existingUid !== '' && $normalizedHgId !== null && $existingUid !== $normalizedHgId) {
                throw new SkportAuthException(
                    sprintf('The logged-in Gryphline account (UID %s) does not match existing Account #%d (UID %s).', $normalizedHgId, $reloginAccount->getId(), $existingUid),
                    0,
                    null,
                    Response::HTTP_UNPROCESSABLE_ENTITY
                );
            }
            $account = $reloginAccount;
        } else {
            $account = null;
            if ($normalizedHgId !== null) {
                $account = $this->accountRepository->findOneBy([
                    'user' => $user,
                    'accountUid' => $normalizedHgId,
                ]);
            }

            if ($account === null) {
                $account = (new GryphlineAccount())->setUser($user);
                $this->entityManager->persist($account);
            }
        }

        if ($normalizedHgId !== null) {
            $account->setAccountUid($normalizedHgId);
        }
        $account->setAccountToken(trim($accountToken));
        $this->skportAuthService->getOrRefreshCredentials($account, force: true);

        try {
            $this->skportManagerService->updateCachedGameProfiles($account);
            $this->entityManager->flush();
        } catch (\Throwable $e) {
            $this->logger->warning('GryphlineAuth: Auto-sync of game profiles after login failed: ' . $e->getMessage(), [
                'accountId' => $account->getId(),
                'exception' => $e,
            ]);
        }

        return $account;
    }

    /**
     * @param array<string, mixed>|null $captcha
     * @return array<string, mixed>
     */
    private function executeEmailPasswordLogin(
        string $email,
        #[\SensitiveParameter]
        string $password,
        string $deviceId,
        ?array $captcha = null,
    ): array {
        $body = [
            'email' => $email,
            'password' => $password,
        ];

        if ($captcha !== null) {
            $body['captcha'] = $captcha;
        }

        try {
            $response = $this->asClient->request(Request::METHOD_POST, self::LOGIN_ENDPOINT, [
                'headers' => [
                    'X-DeviceId' => $deviceId,
                    'X-DeviceName' => 'Gryphline Integration',
                    'X-DeviceModel' => 'WebsiteValdi',
                    'X-DeviceType' => '7',
                    'X-OSVer' => 'Linux',
                ],
                'json' => $body,
            ]);

            return $response->toArray(false);
        } catch (\Throwable $e) {
            throw new SkportAuthException(
                'Failed to connect to Gryphline authentication server: ' . $e->getMessage(),
                0,
                $e,
                Response::HTTP_BAD_GATEWAY
            );
        }
    }

    /**
     * @param array<string, mixed> $geetestPayload
     * @return array<string, string>
     */
    private function buildCaptchaPayload(array $geetestPayload, string $challenge): array
    {
        $requiredKeys = ['captcha_id', 'lot_number', 'pass_token', 'gen_time', 'captcha_output'];
        $result = [];

        foreach ($requiredKeys as $key) {
            $val = isset($geetestPayload[$key]) && is_scalar($geetestPayload[$key]) ? trim((string) $geetestPayload[$key]) : '';
            if ($val === '') {
                throw new SkportAuthException(sprintf('Missing required GeeTest field "%s" in captcha payload.', $key), 0, null, Response::HTTP_BAD_REQUEST);
            }
            $result[$key] = $val;
        }

        $result['challenge'] = $challenge;

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadSessionOrFail(User $user, string $loginSessionId): array
    {
        $session = $this->authSessionStore->get($this->authSessionCache, $user, $loginSessionId);
        if ($session === null) {
            throw new SkportAuthException('Login session expired or invalid. Please start again.', 0, null, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $session;
    }
}
