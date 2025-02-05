<?php

namespace App\PasswordsBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER_PASSWORDS', null, 'Access Denied.')]
#[Route('/api', name: 'api_', format: 'json')]
class ApiController extends AbstractController
{

    public function __construct()
    {
    }

}