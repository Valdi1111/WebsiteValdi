<?php

namespace App\CoreBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Profile page of the logged-in user, the same on every subdomain (no host on core routes).
 * It wins over the "/{path}" catch-all of the bundle apps, which have a lower priority.
 */
#[IsGranted('ROLE_USER', null, 'Access Denied.')]
class ProfileController extends AbstractController
{
    #[Route('/profile', name: 'profile', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('@Core/profile/index.html.twig');
    }
}
