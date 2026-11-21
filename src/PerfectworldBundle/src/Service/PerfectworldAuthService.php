<?php

namespace App\PerfectworldBundle\Service;

use App\CoreBundle\Entity\User;
use App\GamesBundle\Model\Auth\LoginStepResult;
use App\GamesBundle\Service\AuthSessionStore;
use App\PerfectworldBundle\Entity\PerfectworldAccount;
use App\PerfectworldBundle\Exception\PerfectworldApiException;
use App\PerfectworldBundle\Exception\PerfectworldAuthException;
use App\PerfectworldBundle\Model\Sdk\PwRole;
use App\PerfectworldBundle\Repository\PerfectworldAccountRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Perfect World account login: email + code wizard and manual token paste.
 */
class PerfectworldAuthService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PerfectworldAccountRepository $accountRepository,
        private readonly PerfectworldSdkClient $sdkClient,
        private readonly PerfectworldProfileSyncService $profileSyncService,
        private readonly AuthSessionStore $authSessionStore,
        #[Target('perfectworld.auth_session.cache')]
        private readonly CacheItemPoolInterface $authSessionCache,
        #[Target('perfectworld')]
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Sends the login code by email and opens a login session.
     */
    public function startLogin(User $user, string $email, ?PerfectworldAccount $reloginAccount = null): LoginStepResult
    {
        if ($reloginAccount !== null && $reloginAccount->getUser() !== $user) {
            throw new AccessDeniedException('Cannot re-login an account belonging to another user.');
        }

        $ndid = PerfectworldSdkClient::generateNdid();
        $this->sdkClient->sendEmailCode($email, $ndid);

        $loginSessionId = $this->authSessionStore->create($this->authSessionCache, $user, [
            'email' => $email,
            'ndid' => $ndid,
            'reloginAccountId' => $reloginAccount?->getId(),
        ]);

        return $this->codeSentResult($loginSessionId, $email);
    }

    /**
     * Sends a new code for an open login session (same email and device id).
     */
    public function resendCode(User $user, string $loginSessionId): LoginStepResult
    {
        $session = $this->getLoginSession($user, $loginSessionId);
        $this->sdkClient->sendEmailCode((string) $session['email'], (string) $session['ndid']);
        $this->authSessionStore->update($this->authSessionCache, $user, $loginSessionId, $session);

        return $this->codeSentResult($loginSessionId, (string) $session['email']);
    }

    /**
     * Logs in with the emailed code, then creates or updates the account and syncs its profiles.
     */
    public function verifyCode(User $user, string $loginSessionId, #[\SensitiveParameter] string $code): LoginStepResult
    {
        $session = $this->getLoginSession($user, $loginSessionId);
        $email = (string) $session['email'];
        $ndid = (string) $session['ndid'];

        $login = $this->sdkClient->loginWithEmailCode($email, $code, $ndid);

        $reloginAccount = null;
        if (!empty($session['reloginAccountId'])) {
            $reloginAccount = $this->accountRepository->find((int) $session['reloginAccountId']);
            if ($reloginAccount === null) {
                throw new BadRequestHttpException('The account to re-login no longer exists.');
            }
        }

        $account = $this->finalizeAccount(
            user: $user,
            uid: $login->uid,
            token: $login->token,
            ndid: $ndid,
            loginMethod: PerfectworldAccount::LOGIN_METHOD_EMAIL_CODE,
            email: $email,
            reloginAccount: $reloginAccount,
        );

        $this->authSessionStore->delete($this->authSessionCache, $loginSessionId);

        return new LoginStepResult(status: LoginStepResult::STATUS_AUTHENTICATED, account: $account);
    }

    /**
     * Adds (or re-logs) an account from a token copied from the Perfect World website.
     */
    public function loginWithToken(User $user, string $uid, #[\SensitiveParameter] string $token, ?PerfectworldAccount $reloginAccount = null): PerfectworldAccount
    {
        try {
            $roles = $this->sdkClient->getRoles($uid, $token);
        } catch (PerfectworldAuthException) {
            throw new PerfectworldApiException('Invalid or expired token.', PerfectworldAuthException::CODE_TOKEN_EXPIRED, Response::HTTP_BAD_REQUEST);
        }

        return $this->finalizeAccount(
            user: $user,
            uid: $uid,
            token: $token,
            ndid: PerfectworldSdkClient::generateNdid(),
            loginMethod: PerfectworldAccount::LOGIN_METHOD_TOKEN,
            email: null,
            reloginAccount: $reloginAccount,
            roles: $roles,
        );
    }

    /**
     * @param list<PwRole>|null $roles
     */
    private function finalizeAccount(
        User $user,
        string $uid,
        #[\SensitiveParameter] string $token,
        string $ndid,
        string $loginMethod,
        ?string $email,
        ?PerfectworldAccount $reloginAccount,
        ?array $roles = null,
    ): PerfectworldAccount {
        if ($reloginAccount !== null) {
            if ($reloginAccount->getUser() !== $user) {
                throw new AccessDeniedException('Cannot re-login an account belonging to another user.');
            }
            if ($reloginAccount->getPwUid() !== $uid) {
                throw new PerfectworldApiException(sprintf(
                    'The logged-in Perfect World account (UID %s) does not match Account #%d (UID %s).',
                    $uid,
                    $reloginAccount->getId(),
                    $reloginAccount->getPwUid(),
                ));
            }
            $account = $reloginAccount;
        } else {
            $account = $this->accountRepository->findOneBy(['user' => $user, 'pwUid' => $uid]);
            if ($account === null) {
                $account = (new PerfectworldAccount())->setUser($user)->setPwUid($uid);
                $this->entityManager->persist($account);
            }
        }

        $account
            ->setToken($token)
            ->setNdid($ndid)
            ->setLoginMethod($loginMethod)
            ->setTokenInvalid(false);
        if ($email !== null) {
            $account->setEmail($email);
        }

        try {
            $account->setTokenExpiresAt($this->sdkClient->getTokenExpiry($uid, $token, $ndid));
        } catch (PerfectworldAuthException) {
            // The token was just accepted: an expiry check failure must not fail the login
            $account->setTokenExpiresAt(null);
        }

        $this->entityManager->flush();

        try {
            $this->profileSyncService->syncProfiles($account, $roles);
        } catch (\Throwable $e) {
            $this->logger->warning('Perfect World profile sync after login failed.', [
                'account_id' => $account->getId(),
                'error' => $e->getMessage(),
            ]);
        }

        return $account;
    }

    /**
     * @return array<string, mixed>
     */
    private function getLoginSession(User $user, string $loginSessionId): array
    {
        $session = $this->authSessionStore->get($this->authSessionCache, $user, $loginSessionId);
        if ($session === null || empty($session['email']) || empty($session['ndid'])) {
            throw new BadRequestHttpException('Login session expired, please start again.');
        }

        return $session;
    }

    private function codeSentResult(string $loginSessionId, string $email): LoginStepResult
    {
        return new LoginStepResult(
            status: LoginStepResult::STATUS_EMAIL_VERIFICATION_REQUIRED,
            loginSessionId: $loginSessionId,
            message: sprintf('A verification code was sent to %s.', $email),
        );
    }
}
