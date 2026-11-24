<?php

namespace App\CoreBundle\Controller;

use App\CoreBundle\Entity\User;
use App\CoreBundle\Exception\FieldViolationHttpException;
use App\CoreBundle\Exception\InvalidAvatarException;
use App\CoreBundle\Exception\InvalidCurrentPasswordException;
use App\CoreBundle\Model\Profile\ChangePasswordPayload;
use App\CoreBundle\Model\Profile\CurrentUserView;
use App\CoreBundle\Service\Password\UserPasswordManager;
use App\CoreBundle\Service\UserAvatarManager;
use App\CoreBundle\Service\UserRoleResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Attribute\MapUploadedFile;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The logged-in user, for the frontend of every subdomain: core routes have no host
 * and the session cookie is shared on %domain_name%.
 */
#[IsGranted('ROLE_USER', null, 'Access Denied.')]
#[Route('/api/me', name: 'api_me_', format: 'json')]
class ApiMeController extends AbstractController
{
    public function __construct(
        private readonly UserRoleResolver $roleResolver,
        private readonly UserAvatarManager $avatarManager,
        private readonly UserPasswordManager $passwordManager,
    ) {
    }

    /**
     * Loaded once by every page (AppRoot): role names only, the details are in apiMeRoles().
     */
    #[Route('', name: 'get', methods: ['GET'])]
    public function apiMeGet(#[CurrentUser] User $user): JsonResponse
    {
        return $this->json($this->currentUserView($user));
    }

    /**
     * Roles with display name, bundle and whether they are assigned or inherited, for the profile page.
     */
    #[Route('/roles', name: 'roles', methods: ['GET'])]
    public function apiMeRoles(#[CurrentUser] User $user): JsonResponse
    {
        return $this->json($this->roleResolver->getRoleViews($user));
    }

    /**
     * Replaces the profile image (multipart field "avatar"); answers with the updated current user.
     */
    #[Route('/avatar', name: 'avatar_upload', methods: ['POST'])]
    public function apiMeAvatarUpload(
        #[CurrentUser] User $user,
        #[MapUploadedFile([
            new Assert\Image(
                // 5,000,000 bytes, the limit AvatarEditor checks before uploading
                maxSize: '5M',
                mimeTypes: ['image/jpeg', 'image/png', 'image/webp'],
                mimeTypesMessage: 'The image must be a JPEG, PNG or WebP file.',
                minWidth: 128,
                maxWidth: 4096,
                maxHeight: 4096,
                minHeight: 128,
            ),
        ], name: 'avatar')]
        UploadedFile|array|null $avatar,
    ): JsonResponse {
        // MapUploadedFile validates only a sent file, and gives a list for "avatar[]"
        if (!$avatar instanceof UploadedFile) {
            throw self::invalidAvatar('Choose an image to upload.');
        }

        try {
            $this->avatarManager->replace($user, $avatar);
        } catch (InvalidAvatarException $e) {
            throw self::invalidAvatar($e->getMessage(), $e);
        }

        return $this->json($this->currentUserView($user));
    }

    /**
     * Goes back to the default image; answers with the updated current user.
     */
    #[Route('/avatar', name: 'avatar_delete', methods: ['DELETE'])]
    public function apiMeAvatarDelete(#[CurrentUser] User $user): JsonResponse
    {
        $this->avatarManager->remove($user);

        return $this->json($this->currentUserView($user));
    }

    /**
     * Changes the password with the current one; the other sessions and remember-me logins of the user end.
     * Too many wrong current passwords answer 429 (TooManyPasswordAttemptsException).
     */
    #[Route('/password', name: 'password', methods: ['POST'])]
    public function apiMePassword(#[CurrentUser] User $user, #[MapRequestPayload] ChangePasswordPayload $payload): Response
    {
        try {
            $this->passwordManager->change($user, $payload->currentPassword, $payload->newPassword);
        } catch (InvalidCurrentPasswordException $e) {
            throw new FieldViolationHttpException('currentPassword', $e->getMessage(), $e);
        }

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    private static function invalidAvatar(string $message, ?\Throwable $previous = null): FieldViolationHttpException
    {
        return new FieldViolationHttpException('avatar', $message, $previous);
    }

    private function currentUserView(User $user): CurrentUserView
    {
        return CurrentUserView::fromUser($user, $this->roleResolver->getReachableRoleNames($user), $this->avatarManager->getUrl($user));
    }
}
