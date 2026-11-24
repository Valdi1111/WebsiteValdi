<?php

namespace App\CoreBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Admin pages of the whole site (e.g. /admin/users), the same on every subdomain (no host on core routes), for the
 * site-wide admins. Like the profile page, it wins over the "/{path}" catch-all of the bundle apps, which have a lower
 * priority. Its pages are routes of the React app (AdminApp), which uses ApiAdminUsersController.
 */
#[IsGranted('ROLE_ADMIN', null, 'Access Denied.')]
class AdminController extends AbstractController
{
    #[Route('/admin/{path}', name: 'admin', requirements: ['path' => '.*'], defaults: ['path' => ''], methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('@Core/admin/index.html.twig');
    }
}
