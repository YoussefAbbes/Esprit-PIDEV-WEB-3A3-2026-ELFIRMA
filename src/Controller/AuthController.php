<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AuthController extends AbstractController
{
    #[Route('/', name: 'app_login', methods: ['GET', 'POST'])]
    public function login(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            return $this->redirectToRoute('app_pages_home');
        }
        return $this->render('auth/login.html.twig');
    }

    #[Route('/signup', name: 'app_signup', methods: ['GET', 'POST'])]
    public function signup(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            return $this->redirectToRoute('app_login');
        }
        return $this->render('auth/signup.html.twig');
    }

    #[Route('/home', name: 'app_pages_home')]
    public function home(): Response
    {
        return $this->render('pages/index.html.twig');
    }
}
