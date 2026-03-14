<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/minimal')]
class MinimalController extends AbstractController
{

    #[Route('/', name: 'minimal_home')]
    public function home(): Response
    {
        return $this->render('minimal/home.html.twig');
    }

    #[Route('/status', name: 'minimal_status')]
    public function status(): Response
    {
        return $this->render('minimal/status.html.twig');
    }

    #[Route('/about', name: 'minimal_about')]
    public function about(): Response
    {
        return $this->render('minimal/about.html.twig');
    }

    #[Route('/print', name: 'minimal_print')]
    public function print(): Response
    {
        return $this->render('minimal/print.html.twig');
    }

}
