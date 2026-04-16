<?php

namespace App\Controller;

use App\Repository\CultureRepository;
use App\Repository\ParcelleRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    #[Route('/client/parcelles', name: 'app_client_parcelles')]
    public function clientParcelles(ParcelleRepository $parcelleRepository): Response
    {
        $parcelles = $parcelleRepository->findAllWithCultures();

        return $this->render('pages/client_parcelles.html.twig', [
            'parcelles' => $parcelles,
            'stats' => [
                'total' => count($parcelles),
                'available' => $parcelleRepository->countByStatus('Available'),
                'occupied' => $parcelleRepository->countByStatus('Occupied'),
                'resting' => $parcelleRepository->countByStatus('Resting'),
                'totalArea' => $parcelleRepository->getTotalArea(),
            ],
        ]);
    }

    #[Route('/client/cultures', name: 'app_client_cultures')]
    public function clientCultures(CultureRepository $cultureRepository): Response
    {
        $cultures = $cultureRepository->findAllWithParcelle();

        $totalPlanted = 0.0;
        $totalHarvested = 0.0;

        foreach ($cultures as $culture) {
            $totalPlanted += $culture->getQuantitePlantee();
            $totalHarvested += $culture->getQuantiteRecoltee();
        }

        return $this->render('pages/client_cultures.html.twig', [
            'cultures' => $cultures,
            'stats' => [
                'total' => count($cultures),
                'inProgress' => $cultureRepository->countByStatus('In Progress'),
                'planned' => $cultureRepository->countByStatus('Planned'),
                'harvested' => $cultureRepository->countByStatus('Harvested'),
                'totalPlanted' => $totalPlanted,
                'totalHarvested' => $totalHarvested,
            ],
        ]);
    }

    #[Route('/about', name: 'app_about')]
    public function about(): Response
    {
        return $this->render('pages/about.html.twig');
    }

    #[Route('/services', name: 'app_services')]
    public function services(): Response
    {
        return $this->render('pages/services.html.twig');
    }

    #[Route('/testimonials', name: 'app_testimonials')]
    public function testimonials(): Response
    {
        return $this->render('pages/testimonials.html.twig');
    }

    #[Route('/blog', name: 'app_blog')]
    public function blog(): Response
    {
        return $this->render('pages/blog.html.twig');
    }

    #[Route('/blog/details', name: 'app_blog_details')]
    public function blogDetails(): Response
    {
        return $this->render('pages/blog-details.html.twig');
    }

    #[Route('/contact', name: 'app_contact')]
    public function contact(): Response
    {
        return $this->render('pages/contact.html.twig');
    }

    #[Route('/contact/submit', name: 'app_contact_submit', methods: ['POST'])]
    public function submitContact(Request $request): Response
    {
        $name = trim((string) $request->request->get('name', ''));
        $email = trim((string) $request->request->get('email', ''));
        $subject = trim((string) $request->request->get('subject', ''));
        $message = trim((string) $request->request->get('message', ''));

        if ($name === '' || $email === '' || $subject === '' || $message === '') {
            return new Response('Please fill in all required fields.', Response::HTTP_OK, ['Content-Type' => 'text/plain']);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return new Response('Please provide a valid email address.', Response::HTTP_OK, ['Content-Type' => 'text/plain']);
        }

        return new Response('OK', Response::HTTP_OK, ['Content-Type' => 'text/plain']);
    }

    #[Route('/newsletter/submit', name: 'app_newsletter_submit', methods: ['POST'])]
    public function submitNewsletter(Request $request): Response
    {
        $email = trim((string) $request->request->get('email', ''));

        if ($email === '') {
            return new Response('Please enter your email.', Response::HTTP_OK, ['Content-Type' => 'text/plain']);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return new Response('Please provide a valid email address.', Response::HTTP_OK, ['Content-Type' => 'text/plain']);
        }

        return new Response('OK', Response::HTTP_OK, ['Content-Type' => 'text/plain']);
    }
}
