<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class AuthController extends AbstractController
{

    #[Route('/login', name: 'login')]
    public function login(): Response
    {
        return $this->render('auth/login.html.twig');
    }


    #[Route('/register', name: 'register')]
    public function register(): Response
    {
        return $this->render('auth/register.html.twig');
    }


    #[Route('/unlock', name: 'unlock')]
    public function unlock(): Response
    {
        return $this->render('auth/unlock.html.twig');
    }

}