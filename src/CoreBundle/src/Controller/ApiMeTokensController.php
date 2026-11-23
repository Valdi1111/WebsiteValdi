<?php

namespace App\CoreBundle\Controller;

use App\CoreBundle\Entity\Token;
use App\CoreBundle\Entity\User;
use App\CoreBundle\Model\Profile\ApiTokenView;
use App\CoreBundle\Model\Profile\CreateApiTokenPayload;
use App\CoreBundle\Model\Profile\UpdateApiTokenPayload;
use App\CoreBundle\Repository\TokenRepository;
use App\CoreBundle\Security\ApiTokenVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * API tokens of the logged-in user, for the "Tokens" tab of the profile page: active and disabled ones.
 * Not reachable with an API token (ApiTokenVoter). A token of another user answers 404, as a missing one.
 */
#[IsGranted('ROLE_USER', null, 'Access Denied.')]
#[IsGranted(ApiTokenVoter::MANAGE, null, 'Manage the API tokens from the profile page, not with an API token.')]
#[Route('/api/me/tokens', name: 'api_me_tokens_', format: 'json')]
class ApiMeTokensController extends AbstractController
{
    public function __construct(private readonly TokenRepository $tokenRepository)
    {
    }

    /**
     * Without the values: they are given one at a time, by apiMeTokensShow().
     */
    #[Route('', name: 'list', methods: ['GET'])]
    public function apiMeTokensList(#[CurrentUser] User $user): JsonResponse
    {
        return $this->json($this->tokenRepository->findViewsOfUser($user));
    }

    /**
     * Creates an active token; answers with its value.
     */
    #[Route('', name: 'create', methods: ['POST'])]
    public function apiMeTokensCreate(#[CurrentUser] User $user, #[MapRequestPayload] CreateApiTokenPayload $payload): JsonResponse
    {
        $token = Token::createFor($user, trim($payload->description));
        $this->tokenRepository->save($token);

        return $this->json(
            new ApiTokenView((int) $token->getId(), $token->getDescription(), true, $token->getAccessToken()),
            Response::HTTP_CREATED,
        );
    }

    /**
     * The token with its value, decrypted.
     */
    #[Route('/{id}', name: 'show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function apiMeTokensShow(#[CurrentUser] User $user, int $id): JsonResponse
    {
        $view = $this->findView($user, $id);

        return $this->json(new ApiTokenView($view->id, $view->description, $view->valid, $this->tokenRepository->findValueOfUser($id, $user)));
    }

    /**
     * Changes the description and/or enables or disables the token; answers with it, without the value.
     */
    #[Route('/{id}', name: 'update', requirements: ['id' => '\d+'], methods: ['PATCH'])]
    public function apiMeTokensUpdate(#[CurrentUser] User $user, int $id, #[MapRequestPayload] UpdateApiTokenPayload $payload): JsonResponse
    {
        if (!$this->tokenRepository->updateOfUser($id, $user, $payload->getChanges())) {
            throw self::notFound();
        }

        return $this->json($this->findView($user, $id));
    }

    #[Route('/{id}', name: 'delete', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function apiMeTokensDelete(#[CurrentUser] User $user, int $id): Response
    {
        if (!$this->tokenRepository->deleteOfUser($id, $user)) {
            throw self::notFound();
        }

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    private function findView(User $user, int $id): ApiTokenView
    {
        return $this->tokenRepository->findViewOfUser($id, $user) ?? throw self::notFound();
    }

    private static function notFound(): NotFoundHttpException
    {
        return new NotFoundHttpException('Token not found.');
    }
}
