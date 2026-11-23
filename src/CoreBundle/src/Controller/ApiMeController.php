<?php

namespace App\CoreBundle\Controller;

use App\CoreBundle\Entity\User;
use App\CoreBundle\Model\Profile\CurrentUserView;
use App\CoreBundle\Service\UserRoleResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The logged-in user, for the frontend of every subdomain: core routes have no host
 * and the session cookie is shared on %domain_name%.
 */
#[IsGranted('ROLE_USER', null, 'Access Denied.')]
#[Route('/api/me', name: 'api_me_', format: 'json')]
class ApiMeController extends AbstractController
{
    public function __construct(private readonly UserRoleResolver $roleResolver)
    {
    }

    /**
     * Loaded once by every page (AppRoot): role names only, the details are in apiMeRoles().
     */
    #[Route('', name: 'get', methods: ['GET'])]
    public function apiMeGet(#[CurrentUser] User $user): JsonResponse
    {
        return $this->json(CurrentUserView::fromUser($user, $this->roleResolver->getReachableRoleNames($user)));
    }

    /**
     * Roles with display name, bundle and whether they are assigned or inherited, for the profile page.
     */
    #[Route('/roles', name: 'roles', methods: ['GET'])]
    public function apiMeRoles(#[CurrentUser] User $user): JsonResponse
    {
        return $this->json($this->roleResolver->getRoleViews($user));
    }
}
