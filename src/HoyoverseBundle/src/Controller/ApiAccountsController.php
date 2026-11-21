<?php

namespace App\HoyoverseBundle\Controller;

use App\CoreBundle\Entity\User;
use App\GamesBundle\Security\GameAccountOwnerVoter;
use App\HoyoverseBundle\Entity\HoyoverseAccount;
use App\HoyoverseBundle\Exception\HoyoverseAuthException;
use App\HoyoverseBundle\Repository\HoyoverseAccountRepository;
use App\HoyoverseBundle\Service\HoyolabManagerService;
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

#[IsGranted('ROLE_USER_HOYOVERSE', null, 'Access Denied.')]
#[Route('/accounts', name: 'api_hoyoverse_accounts_', format: 'json')]
class ApiAccountsController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly HoyoverseAccountRepository $accountRepository
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
        if (!$payload->has('cookie')) {
            throw new BadRequestHttpException("Parameter 'cookie' not found.");
        }

        $cookie = trim($payload->getString('cookie'));
        if ($cookie === '') {
            throw new BadRequestHttpException("Parameter 'cookie' cannot be empty.");
        }

        $accountId = $payload->get('account_id') ?? $payload->get('accountId');
        $extractedUid = $this->extractAccountUidFromCookie($cookie);

        if ($accountId !== null && $accountId !== '') {
            $account = $this->accountRepository->find((int) $accountId);
            if ($account === null) {
                throw $this->createNotFoundException('Account not found.');
            }
            // Not an {account} route, so the owner check is done here
            $this->denyAccessUnlessGranted(GameAccountOwnerVoter::OWNER, $account, 'Cannot update an account belonging to another user.');

            // Same identity check as the credentials login: a cookie of another HoYoverse account is refused, not saved over this one
            $existingUid = trim((string) $account->getAccountUid());
            if ($extractedUid !== null && $existingUid !== '' && $extractedUid !== $existingUid) {
                throw HoyoverseAuthException::accountMismatch($extractedUid, $account->getId(), $existingUid);
            }

            $account->setCookie($cookie)
                ->setCookieRefreshFailed(false);
            if ($extractedUid !== null) {
                $account->setAccountUid($extractedUid);
            }
            $statusCode = Response::HTTP_OK;
        } else {
            $account = null;
            if ($extractedUid !== null) {
                $account = $this->accountRepository->findOneBy([
                    'user' => $user,
                    'accountUid' => $extractedUid,
                ]);
            }

            if ($account !== null) {
                $account->setCookie($cookie)
                    ->setAccountUid($extractedUid)
                    ->setCookieRefreshFailed(false);
                $statusCode = Response::HTTP_OK;
            } else {
                $account = (new HoyoverseAccount())
                    ->setCookie($cookie)
                    ->setAccountUid($extractedUid)
                    ->setUser($user);
                $this->entityManager->persist($account);
                $statusCode = Response::HTTP_CREATED;
            }
        }

        $this->entityManager->flush();

        return $this->json($account, $statusCode, context: [
            AbstractNormalizer::IGNORED_ATTRIBUTES => ['gameProfiles'],
        ]);
    }

    private function extractAccountUidFromCookie(string $cookieString): ?string
    {
        foreach (explode(';', $cookieString) as $part) {
            $part = trim($part);
            if ($part === '' || !str_contains($part, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $part, 2);
            $key = trim($k);
            $val = trim($v);
            if (in_array($key, ['ltuid_v2', 'account_id_v2', 'ltuid', 'account_id'], true) && $val !== '') {
                return $val;
            }
        }

        return null;
    }

    #[Route('/{account}/sync', name: 'sync', requirements: ['account' => '\d+'], methods: ['POST'])]
    #[IsGranted(GameAccountOwnerVoter::OWNER, subject: 'account')]
    public function apiAccountsSync(
        #[MapEntity(message: "Account not found.")] HoyoverseAccount $account,
        HoyolabManagerService $hoyolabManagerService
    ): Response {
        $hoyolabManagerService->updateCachedGameProfilesByGameRecords($account);
        $this->entityManager->flush();

        return $this->json($account->getGameProfiles()->toArray());
    }

    #[Route('/{account}', name: 'delete', requirements: ['account' => '\d+'], methods: ['DELETE'])]
    #[IsGranted(GameAccountOwnerVoter::OWNER, subject: 'account')]
    public function apiAccountsDelete(
        #[MapEntity(message: "Account not found.")] HoyoverseAccount $account
    ): Response {
        $id = $account->getId();
        $this->entityManager->remove($account);
        $this->entityManager->flush();

        return $this->json(['id' => $id]);
    }
}