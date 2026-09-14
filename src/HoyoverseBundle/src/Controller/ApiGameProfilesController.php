<?php

namespace App\HoyoverseBundle\Controller;

use App\CoreBundle\Entity\User;
use App\HoyoverseBundle\Entity\HoyoverseAccount;
use App\HoyoverseBundle\Entity\HoyoverseGameProfile;
use App\HoyoverseBundle\Repository\HoyoverseAccountRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

#[IsGranted('ROLE_USER_HOYOVERSE', null, 'Access Denied.')]
#[Route('/api/accounts/{account}', name: 'api_hoyoverse_game_profiles_', requirements: ['account' => '\d+'], format: 'json')]
class ApiGameProfilesController extends AbstractController
{
    private HoyoverseAccount $account;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly HoyoverseAccountRepository $accountRepo,
        private readonly RequestStack $requestStack
    ) {
        $req = $this->requestStack->getCurrentRequest();
        $accountId = $req?->attributes->getInt('account') ?? 0;
        $account = $this->accountRepo->find($accountId);

        if (!$account) {
            throw $this->createNotFoundException("Hoyoverse Account not found.");
        }

        $this->account = $account;
    }

    protected function getAccount(): HoyoverseAccount
    {
        return $this->account;
    }

    protected function checkAccountOwnership(User $user): void
    {
        if ($this->getAccount()->getUser() !== $user) {
            throw $this->createAccessDeniedException("You do not own this Hoyoverse account.");
        }
    }

    #[Route('/gameProfiles', name: 'get', methods: ['GET'])]
    public function apiGameProfilesGet(#[CurrentUser] User $user): Response
    {
        $this->checkAccountOwnership($user);

        return $this->json($this->getAccount()->getGameProfiles()->toArray());
    }

    #[Route('/gameProfiles/{gameProfile}', name: 'id_get', requirements: ['gameProfile' => '\d+'], methods: ['GET'])]
    public function apiGameProfilesIdGet(
        #[CurrentUser] User $user,
        #[MapEntity(message: "Game profile not found.")] HoyoverseGameProfile $gameProfile
    ): Response {
        $this->checkAccountOwnership($user);

        if ($gameProfile->getAccount() !== $this->getAccount()) {
            throw new BadRequestHttpException("Game profile does not belong to this account.");
        }

        return $this->json($gameProfile);
    }

    #[Route('/gameProfiles/{gameProfile}/settings', name: 'update_settings', requirements: ['gameProfile' => '\d+'], methods: ['PATCH'])]
    public function apiGameProfilesUpdateSettings(
        Request $req,
        #[CurrentUser] User $user,
        #[MapEntity(message: "Game profile not found.")] HoyoverseGameProfile $gameProfile,
        DenormalizerInterface $denormalizer
    ): Response {
        $this->checkAccountOwnership($user);

        if ($gameProfile->getAccount() !== $this->getAccount()) {
            throw new BadRequestHttpException("Game profile does not belong to this account.");
        }

        $denormalizer->denormalize($req->getPayload()->all(), HoyoverseGameProfile::class, null, [
            AbstractNormalizer::OBJECT_TO_POPULATE => $gameProfile,
        ]);

        $this->entityManager->flush();

        return $this->json($gameProfile);
    }
}