<?php

namespace App\CoreBundle\Controller;

use App\CoreBundle\Form\LoginType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class SecurityController extends AbstractController
{

    #[Route('/login', name: 'login', priority: 1000)]
    public function login(AuthenticationUtils $authenticationUtils, FormFactoryInterface $formFactory): Response
    {
        return $this->render('@Core/security/login.html.twig', [
            // last username entered by the user
            'last_username' => $authenticationUtils->getLastUsername(),
            // get the login error if there is one
            'error' => $authenticationUtils->getLastAuthenticationError(),
            'loginForm' => $formFactory->createNamed('', LoginType::class),
        ]);
    }

    #[Route('/logout', name: 'logout', methods: ['GET'], priority: 1000)]
    public function logout(): Response
    {
        // controller can be blank: it will never be called!
        throw new \RuntimeException('Don\'t forget to activate logout in security.yaml');
    }

}
