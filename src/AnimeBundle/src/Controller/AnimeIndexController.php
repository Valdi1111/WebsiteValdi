<?php

namespace App\AnimeBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class AnimeIndexController extends AbstractController
{

    #[Route('/{path}', name: 'index', requirements: ['path' => '.*'], methods: ['GET'], priority: -10)]
    public function index(): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        return $this->render('@AnimeBundle/index.html.twig');
    }

}
