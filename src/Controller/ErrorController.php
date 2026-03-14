<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class ErrorController extends AbstractController
{

    #[Route('/error/403', name: 'error_403')]
    public function error403(): Response
    {
        return $this->render('error/403.html.twig');
    }

    #[Route('/error/404', name: 'error_404')]
    public function error404(): Response
    {
        return $this->render('error/404.html.twig');
    }

    #[Route('/error/500', name: 'error_500')]
    public function error500(): Response
    {
        return $this->render('error/500.html.twig');
    }

}
