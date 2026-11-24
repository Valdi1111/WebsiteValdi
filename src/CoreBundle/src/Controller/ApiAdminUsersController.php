<?php

namespace App\CoreBundle\Controller;

use App\CoreBundle\Entity\User;
use App\CoreBundle\Exception\CannotDisableOwnAccountException;
use App\CoreBundle\Exception\CannotRemoveOwnAdminRoleException;
use App\CoreBundle\Exception\EmailAlreadyUsedException;
use App\CoreBundle\Exception\FieldViolationHttpException;
use App\CoreBundle\Exception\UnknownRoleException;
use App\CoreBundle\Model\Admin\CreateUserPayload;
use App\CoreBundle\Model\Admin\SetUserRolesPayload;
use App\CoreBundle\Model\Admin\UpdateUserPayload;
use App\CoreBundle\Repository\UserRepository;
use App\CoreBundle\Security\ApiTokenVoter;
use App\CoreBundle\Service\Admin\AdminUserManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * API of the users admin page (AdminController), for the site-wide admins only, and not with an API token
 * (ApiTokenVoter::ADMIN_USERS).
 */
#[IsGranted('ROLE_ADMIN', null, 'Access Denied.')]
#[IsGranted(ApiTokenVoter::ADMIN_USERS, null, 'Manage the users from the admin page, not with an API token.')]
#[Route('/api/admin', name: 'api_admin_', format: 'json')]
class ApiAdminUsersController extends AbstractController
{
    public function __construct(
        private readonly AdminUserManager $userManager,
        private readonly UserRepository $userRepository,
    ) {
    }

    /**
     * Every role, to choose those of a user.
     */
    #[Route('/roles', name: 'roles', methods: ['GET'])]
    public function apiAdminRoles(): JsonResponse
    {
        return $this->json($this->userManager->getRoleViews());
    }

    #[Route('/users', name: 'users_list', methods: ['GET'])]
    public function apiAdminUsersList(): JsonResponse
    {
        return $this->json($this->userManager->getUserViews());
    }

    /**
     * Creates a user, who gets an email with a link to choose the password; answers with the user.
     */
    #[Route('/users', name: 'users_create', methods: ['POST'])]
    public function apiAdminUsersCreate(#[MapRequestPayload] CreateUserPayload $payload): JsonResponse
    {
        try {
            $user = $this->userManager->create($payload->email, $payload->password, $payload->roles);
        } catch (EmailAlreadyUsedException $e) {
            throw new FieldViolationHttpException('email', $e->getMessage(), $e);
        } catch (UnknownRoleException $e) {
            throw new FieldViolationHttpException('roles', $e->getMessage(), $e);
        }

        return $this->json($this->userManager->getUserView($user), Response::HTTP_CREATED);
    }

    /**
     * Disables or enables the account; answers with the user.
     */
    #[Route('/users/{id}', name: 'users_update', requirements: ['id' => '\d+'], methods: ['PATCH'])]
    public function apiAdminUsersUpdate(#[CurrentUser] User $admin, int $id, #[MapRequestPayload] UpdateUserPayload $payload): JsonResponse
    {
        $user = $this->findUser($id);

        try {
            $this->userManager->setEnabled($user, (bool) $payload->enabled, $admin);
        } catch (CannotDisableOwnAccountException $e) {
            throw new FieldViolationHttpException('enabled', $e->getMessage(), $e);
        }

        return $this->json($this->userManager->getUserView($user));
    }

    /**
     * Replaces the assigned roles; answers with the user. The user is logged out at their next request, also when it
     * is the admin making the change, and gets the new roles at the next login.
     */
    #[Route('/users/{id}/roles', name: 'users_roles', requirements: ['id' => '\d+'], methods: ['PUT'])]
    public function apiAdminUsersRoles(#[CurrentUser] User $admin, int $id, #[MapRequestPayload] SetUserRolesPayload $payload): JsonResponse
    {
        $user = $this->findUser($id);

        try {
            $this->userManager->setRoles($user, $payload->roles ?? [], $admin);
        } catch (UnknownRoleException|CannotRemoveOwnAdminRoleException $e) {
            throw new FieldViolationHttpException('roles', $e->getMessage(), $e);
        }

        return $this->json($this->userManager->getUserView($user));
    }

    private function findUser(int $id): User
    {
        return $this->userRepository->find($id) ?? throw new NotFoundHttpException('User not found.');
    }
}
