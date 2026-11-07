<?php

namespace App\GryphlineBundle\Controller;

use App\CoreBundle\Attribute\ResolveEntityFromRoute;
use App\CoreBundle\Entity\User;
use App\GryphlineBundle\Entity\GryphlineAccount;
use App\GryphlineBundle\Entity\GryphlineGameProfile;
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
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

#[ResolveEntityFromRoute(setter: 'setAccount')]
#[IsGranted('ROLE_USER_GRYPHLINE', null, 'Access Denied.')]
#[Route('/api/accounts/{account}', name: 'api_gryphline_game_profiles_', requirements: ['account' => '\d+'], format: 'json')]
class ApiGameProfilesController extends AbstractController
{
    private GryphlineAccount $account;

    public function __construct(
        private readonly EntityManagerInterface $entityManager
    ) {
    }

    protected function getAccount(): GryphlineAccount
    {
        return $this->account;
    }

    public function setAccount(#[MapEntity(id: 'account', message: "Gryphline Account not found.")] GryphlineAccount $account): void
    {
        $this->account = $account;
    }

    protected function checkAccountOwnership(User $user): void
    {
        if ($this->getAccount()->getUser() !== $user) {
            throw $this->createAccessDeniedException("You do not own this Gryphline account.");
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
        #[MapEntity(message: "Game profile not found.")] GryphlineGameProfile $gameProfile
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
        #[MapEntity(message: "Game profile not found.")] GryphlineGameProfile $gameProfile,
        DenormalizerInterface $denormalizer
    ): Response {
        $this->checkAccountOwnership($user);

        if ($gameProfile->getAccount() !== $this->getAccount()) {
            throw new BadRequestHttpException("Game profile does not belong to this account.");
        }

        $denormalizer->denormalize($req->getPayload()->all(), GryphlineGameProfile::class, null, [
            AbstractNormalizer::OBJECT_TO_POPULATE => $gameProfile,
        ]);

        $this->entityManager->flush();

        return $this->json($gameProfile);
    }
}
