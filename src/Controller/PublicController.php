<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class PublicController extends AbstractController
{

    #[Route('/', name: 'home')]
    public function home(): Response
    {
        return $this->render('public/home.html.twig');
    }


    #[Route('/about', name: 'about')]
    public function about(): Response
    {
        return $this->render('public/about.html.twig');
    }


    #[Route('/pricing', name: 'pricing')]
    public function pricing(): Response
    {
        return $this->render('public/pricing.html.twig');
    }


    #[Route('/features', name: 'features')]
    public function features(): Response
    {
        return $this->render('public/features.html.twig');
    }


    #[Route('/contact', name: 'contact')]
    public function contact(): Response
    {
        return $this->render('public/contact.html.twig');
    }


    #[Route('/help', name: 'help')]
    public function help(): Response
    {
        return $this->render('public/help.html.twig');
    }


    #[Route('/terms', name: 'terms')]
    public function terms(): Response
    {
        return $this->render('public/terms.html.twig');
    }


    #[Route('/privacy', name: 'privacy')]
    public function privacy(): Response
    {
        return $this->render('public/privacy.html.twig');
    }

}