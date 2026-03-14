<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CustomersController extends AbstractController
{
    #[Route('/customers', name: 'app_customers')]
    public function index(): Response
    {
        return $this->render('customers/index.html.twig', [
            'controller_name' => 'CustomersController',
        ]);
    }
}
