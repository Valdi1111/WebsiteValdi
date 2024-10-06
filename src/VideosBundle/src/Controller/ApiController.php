<?php

namespace App\VideosBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER_VIDEOS', null, 'Access Denied.')]
#[Route('/api', name: 'api_', format: 'json')]
class ApiController extends AbstractController
{

    public function __construct()
    {
    }

}
