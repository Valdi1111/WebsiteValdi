<?php

namespace App\PerfectworldBundle\Controller;

use App\CoreBundle\Entity\User;
use App\GamesBundle\Security\GameAccountOwnerVoter;
use App\PerfectworldBundle\Entity\PerfectworldAccount;
use App\PerfectworldBundle\Model\Auth\AddTokenAccountRequest;
use App\PerfectworldBundle\Repository\PerfectworldAccountRepository;
use App\PerfectworldBundle\Service\PerfectworldAuthService;
use App\PerfectworldBundle\Service\PerfectworldProfileSyncService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER_PERFECTWORLD', null, 'Access Denied.')]
#[Route('/accounts', name: 'api_perfectworld_accounts_', format: 'json')]
class ApiAccountsController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PerfectworldAccountRepository $accountRepository,
    ) {
    }

    #[Route('', name: 'get', methods: ['GET'])]
    public function apiAccountsGet(#[CurrentUser] User $user): Response
    {
        return $this->json($this->accountRepository->findBy(['user' => $user], ['id' => 'ASC']));
    }

    /**
     * New account from a token copied from the Perfect World website ("_websdk_" Local Storage value).
     */
    #[Route('', name: 'add', methods: ['POST'])]
    public function apiAccountsAdd(
        #[CurrentUser] User $user,
        #[MapRequestPayload] AddTokenAccountRequest $payload,
        PerfectworldAuthService $authService,
    ): Response {
        $credentials = (array) $payload->getCredentials();
        $account = $authService->loginWithToken($user, $credentials['uid'], $credentials['token']);

        return $this->json($account, Response::HTTP_CREATED);
    }

    /**
     * Re-login of an existing account with a pasted token (the token must belong to the same Perfect World account).
     */
    #[Route('/{account}/token', name: 'token_relogin', requirements: ['account' => '\d+'], methods: ['POST'])]
    #[IsGranted(GameAccountOwnerVoter::OWNER, subject: 'account')]
    public function apiAccountsTokenRelogin(
        #[CurrentUser] User $user,
        #[MapEntity(message: 'Account not found.')] PerfectworldAccount $account,
        #[MapRequestPayload] AddTokenAccountRequest $payload,
        PerfectworldAuthService $authService,
    ): Response {
        $credentials = (array) $payload->getCredentials();
        $account = $authService->loginWithToken($user, $credentials['uid'], $credentials['token'], $account);

        return $this->json($account);
    }

    /**
     * Refreshes the game profiles from the SDK role list. An expired token flags the account and returns 409.
     */
    #[Route('/{account}/sync', name: 'sync', requirements: ['account' => '\d+'], methods: ['POST'])]
    #[IsGranted(GameAccountOwnerVoter::OWNER, subject: 'account')]
    public function apiAccountsSync(
        #[MapEntity(message: 'Account not found.')] PerfectworldAccount $account,
        PerfectworldProfileSyncService $profileSyncService,
    ): Response {
        $profileSyncService->syncProfiles($account);

        return $this->json($account->getGameProfiles()->toArray());
    }

    #[Route('/{account}', name: 'delete', requirements: ['account' => '\d+'], methods: ['DELETE'])]
    #[IsGranted(GameAccountOwnerVoter::OWNER, subject: 'account')]
    public function apiAccountsDelete(#[MapEntity(message: 'Account not found.')] PerfectworldAccount $account): Response
    {
        $id = $account->getId();
        $this->entityManager->remove($account);
        $this->entityManager->flush();

        return $this->json(['id' => $id]);
    }
}
