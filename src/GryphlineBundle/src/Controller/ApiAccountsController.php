<?php

namespace App\GryphlineBundle\Controller;

use App\CoreBundle\Entity\User;
use App\GamesBundle\Security\GameAccountOwnerVoter;
use App\GryphlineBundle\Entity\GryphlineAccount;
use App\GryphlineBundle\Exception\SkportAuthException;
use App\GryphlineBundle\Repository\GryphlineAccountRepository;
use App\GryphlineBundle\Service\GryphlineAuthService;
use App\GryphlineBundle\Service\SkportAuthService;
use App\GryphlineBundle\Service\SkportManagerService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;

#[IsGranted('ROLE_USER_GRYPHLINE', null, 'Access Denied.')]
#[Route('/accounts', name: 'api_gryphline_accounts_', format: 'json')]
class ApiAccountsController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly GryphlineAccountRepository $accountRepository
    ) {
    }

    #[Route('', name: 'get', methods: ['GET'])]
    public function apiAccountsGet(#[CurrentUser] User $user): Response
    {
        $accounts = $this->accountRepository->findBy(['user' => $user], ['id' => 'ASC']);

        return $this->json($accounts);
    }

    /**
     * Adds an account from a pasted ACCOUNT_TOKEN, or re-logs the account given in "account_id".
     * The token is identified first (hgId): invalid tokens are rejected before anything is saved, new accounts are
     * deduplicated by hgId and a re-login with a token of another Gryphline account is refused, as in the credentials login.
     */
    #[Route('', name: 'add', methods: ['POST'])]
    public function apiAccountsAdd(
        Request $req,
        #[CurrentUser] User $user,
        SkportAuthService $skportAuthService,
        GryphlineAuthService $authService,
    ): Response {
        $payload = $req->getPayload();
        $token = trim((string) ($payload->get('account_token') ?? $payload->get('accountToken') ?? $payload->get('token') ?? ''));

        if ($token === '') {
            throw new BadRequestHttpException("Parameter 'account_token' cannot be empty.");
        }

        $reloginAccount = null;
        $accountId = $payload->get('account_id') ?? $payload->get('accountId');
        if ($accountId !== null && $accountId !== '') {
            $reloginAccount = $this->accountRepository->find((int) $accountId);
            if ($reloginAccount === null) {
                throw $this->createNotFoundException('Account not found.');
            }
            // Not an {account} route, so the owner check is done here before calling Gryphline
            $this->denyAccessUnlessGranted(GameAccountOwnerVoter::OWNER, $reloginAccount, 'Cannot update an account belonging to another user.');
        }

        $hgId = $skportAuthService->fetchAccountInfo($token)->hgId;
        if ($hgId === null) {
            throw new SkportAuthException('Gryphline did not return the account UID for this token.');
        }

        $existingAccount = $reloginAccount ?? $this->accountRepository->findOneBy(['user' => $user, 'accountUid' => $hgId]);
        $account = $authService->finalizeAuthenticatedAccount($user, $token, $reloginAccount, $hgId);

        return $this->json($account, $existingAccount !== null ? Response::HTTP_OK : Response::HTTP_CREATED, context: [
            AbstractNormalizer::IGNORED_ATTRIBUTES => ['gameProfiles'],
        ]);
    }

    #[Route('/{account}/sync', name: 'sync', requirements: ['account' => '\d+'], methods: ['POST'])]
    #[IsGranted(GameAccountOwnerVoter::OWNER, subject: 'account')]
    public function apiAccountsSync(
        #[MapEntity(message: "Account not found.")] GryphlineAccount $account,
        SkportManagerService $skportManagerService
    ): Response {
        $skportManagerService->updateCachedGameProfiles($account);
        $this->entityManager->flush();

        return $this->json($account->getGameProfiles()->toArray());
    }

    #[Route('/{account}', name: 'delete', requirements: ['account' => '\d+'], methods: ['DELETE'])]
    #[IsGranted(GameAccountOwnerVoter::OWNER, subject: 'account')]
    public function apiAccountsDelete(
        #[MapEntity(message: "Account not found.")] GryphlineAccount $account
    ): Response {
        $id = $account->getId();
        $this->entityManager->remove($account);
        $this->entityManager->flush();

        return $this->json(['id' => $id]);
    }
}
