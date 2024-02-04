<?php

namespace App\VideosBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class VideosIndexController extends AbstractController
{

    #[Route('/{path}', name: 'index', requirements: ['path' => '.*'], methods: ['GET'], priority: -10)]
    public function index(): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        return $this->render('@VideosBundle/index.html.twig');
    }

}
