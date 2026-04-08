<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Culture;
use App\Form\CultureType;
use App\Repository\CultureRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/elfirma/cultures')]
final class CultureController extends AbstractController
{
    #[Route('', name: 'culture_index', methods: ['GET'])]
    public function index(Request $request, CultureRepository $cultureRepository): Response
    {
        $page = max(1, $request->query->getInt('page', 1));
        $limit = $request->query->getInt('limit', 10);
        $search = $request->query->get('search', '');
        $sortBy = $request->query->get('sort', 'id');
        $sortOrder = $request->query->get('order', 'DESC');

        $result = $cultureRepository->findPaginated($page, $limit, $search ?: null, $sortBy, $sortOrder);

        return $this->render('elfirma/cultures/index.html.twig', [
            'cultures' => $result['data'],
            'pagination' => [
                'page' => $result['page'],
                'limit' => $result['limit'],
                'total' => $result['total'],
                'totalPages' => $result['totalPages'],
            ],
            'search' => $search,
            'sortBy' => $sortBy,
            'sortOrder' => $sortOrder,
        ]);
    }

    #[Route('/new', name: 'culture_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $culture = new Culture();
        $form = $this->createForm(CultureType::class, $culture);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($culture);
            $entityManager->flush();

            $this->addFlash('success', 'Crop created successfully!');

            return $this->redirectToRoute('culture_index');
        }

        return $this->render('elfirma/cultures/new.html.twig', [
            'culture' => $culture,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'culture_show', methods: ['GET'])]
    public function show(Culture $culture): Response
    {
        return $this->render('elfirma/cultures/show.html.twig', [
            'culture' => $culture,
        ]);
    }

    #[Route('/{id}/edit', name: 'culture_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Culture $culture, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(CultureType::class, $culture);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            $this->addFlash('success', 'Crop updated successfully!');

            return $this->redirectToRoute('culture_index');
        }

        return $this->render('elfirma/cultures/edit.html.twig', [
            'culture' => $culture,
            'form' => $form,
        ]);
    }

    #[Route('/{id}/delete', name: 'culture_delete', methods: ['POST'])]
    public function delete(Request $request, Culture $culture, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete' . $culture->getId(), $request->request->get('_token'))) {
            $entityManager->remove($culture);
            $entityManager->flush();

            $this->addFlash('success', 'Crop deleted successfully!');
        }

        return $this->redirectToRoute('culture_index');
    }

    #[Route('/{id}/image', name: 'culture_image', methods: ['GET'])]
    public function image(Culture $culture): Response
    {
        $blob = $culture->getImage();
        if ($blob === null) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        $content = \is_resource($blob) ? stream_get_contents($blob) : (string) $blob;
        if ($content === false || $content === '') {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->buffer($content) ?: 'image/jpeg';

        return new Response($content, Response::HTTP_OK, [
            'Content-Type' => $mimeType,
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
