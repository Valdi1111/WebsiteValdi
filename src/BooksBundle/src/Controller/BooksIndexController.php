<?php

namespace App\BooksBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class BooksIndexController extends AbstractController
{

    #[Route('/{path}', name: 'index', requirements: ['path' => '.*'], methods: ['GET'], priority: -10)]
    public function index(): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        return $this->render('@BooksBundle/index.html.twig');
    }

}
