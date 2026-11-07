<?php

namespace App\GryphlineBundle\Controller;

use App\CoreBundle\Entity\User;
use App\GryphlineBundle\Entity\GryphlineAccount;
use App\GryphlineBundle\Repository\GryphlineAccountRepository;
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
#[Route('/api/accounts', name: 'api_gryphline_accounts_', format: 'json')]
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

    #[Route('', name: 'add', methods: ['POST'])]
    public function apiAccountsAdd(Request $req, #[CurrentUser] User $user): Response
    {
        $payload = $req->getPayload();
        $token = trim((string) ($payload->get('account_token') ?? $payload->get('accountToken') ?? $payload->get('token') ?? ''));

        if ($token === '') {
            throw new BadRequestHttpException("Parameter 'account_token' cannot be empty.");
        }

        $account = new GryphlineAccount()
            ->setAccountToken($token)
            ->setUser($user);

        $this->entityManager->persist($account);
        $this->entityManager->flush();

        return $this->json($account, Response::HTTP_CREATED, context: [
            AbstractNormalizer::IGNORED_ATTRIBUTES => ['gameProfiles'],
        ]);
    }

    #[Route('/{account}/sync', name: 'sync', requirements: ['account' => '\d+'], methods: ['POST'])]
    public function apiAccountsSync(
        #[CurrentUser] User $user,
        #[MapEntity(message: "Account not found.")] GryphlineAccount $account,
        SkportManagerService $skportManagerService
    ): Response {
        if ($account->getUser() !== $user) {
            throw $this->createAccessDeniedException("Cannot sync an account belonging to another user.");
        }

        $skportManagerService->updateCachedGameProfiles($account);
        $this->entityManager->flush();

        return $this->json($account->getGameProfiles()->toArray());
    }

    #[Route('/{account}', name: 'delete', requirements: ['account' => '\d+'], methods: ['DELETE'])]
    public function apiAccountsDelete(
        #[CurrentUser] User $user,
        #[MapEntity(message: "Account not found.")] GryphlineAccount $account
    ): Response {
        if ($account->getUser() !== $user) {
            throw $this->createAccessDeniedException("Cannot delete an account belonging to another user.");
        }

        $id = $account->getId();
        $this->entityManager->remove($account);
        $this->entityManager->flush();

        return $this->json(['id' => $id]);
    }
}
