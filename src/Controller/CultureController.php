<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Culture;
use App\Form\CultureType;
use App\Repository\CultureRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Form\FormError;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
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
            /** @var UploadedFile|null $imageFile */
            $imageFile = $form->get('imageFile')->getData();
            if ($imageFile instanceof UploadedFile) {
                $error = null;
                $content = $this->readValidatedImageBlob($imageFile, $error);
                if ($content === null) {
                    $form->addError(new FormError($error ?? 'Invalid image upload.'));
                } else {
                    $culture->setImage($content);
                }
            }

            if ($form->getErrors(true)->count() > 0) {
                return $this->render('elfirma/cultures/new.html.twig', [
                    'culture' => $culture,
                    'form' => $form->createView(),
                ]);
            }

            try {
                $entityManager->persist($culture);
                $entityManager->flush();

                $this->addFlash('success', 'Crop created successfully!');

                return $this->redirectToRoute('culture_index');
            } catch (\Throwable $e) {
                $form->addError(new FormError('Unable to save crop. Please check highlighted fields and try again.'));
            }
        }

        return $this->render('elfirma/cultures/new.html.twig', [
            'culture' => $culture,
            'form' => $form->createView(),
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
            /** @var UploadedFile|null $imageFile */
            $imageFile = $form->get('imageFile')->getData();
            if ($imageFile instanceof UploadedFile) {
                $error = null;
                $content = $this->readValidatedImageBlob($imageFile, $error);
                if ($content === null) {
                    $form->addError(new FormError($error ?? 'Invalid image upload.'));
                } else {
                    $culture->setImage($content);
                }
            }

            if ($form->getErrors(true)->count() > 0) {
                return $this->render('elfirma/cultures/edit.html.twig', [
                    'culture' => $culture,
                    'form' => $form->createView(),
                ]);
            }

            try {
                $entityManager->flush();

                $this->addFlash('success', 'Crop updated successfully!');

                return $this->redirectToRoute('culture_index');
            } catch (\Throwable $e) {
                $form->addError(new FormError('Unable to update crop. Please check highlighted fields and try again.'));
            }
        }

        return $this->render('elfirma/cultures/edit.html.twig', [
            'culture' => $culture,
            'form' => $form->createView(),
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

        $mimeType = $this->detectImageMimeTypeFromContent($content);

        return new Response($content, Response::HTTP_OK, [
            'Content-Type' => $mimeType,
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    private function readValidatedImageBlob(UploadedFile $imageFile, ?string &$error = null): ?string
    {
        $maxSizeBytes = 5 * 1024 * 1024;
        $size = (int) ($imageFile->getSize() ?? 0);
        if ($size > $maxSizeBytes) {
            $error = 'Image is too large (max 5MB).';
            return null;
        }

        $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        $extension = strtolower((string) ($imageFile->getClientOriginalExtension() ?: pathinfo($imageFile->getClientOriginalName(), PATHINFO_EXTENSION)));
        if ($extension !== '' && !in_array($extension, $allowedExtensions, true)) {
            $error = 'Invalid image format. Allowed: JPG, JPEG, PNG, WEBP, GIF.';
            return null;
        }

        $content = @file_get_contents($imageFile->getPathname());
        if ($content === false || $content === '') {
            $error = 'Unable to read uploaded image.';
            return null;
        }

        if (@getimagesizefromstring($content) === false) {
            $error = 'Uploaded file is not a valid image.';
            return null;
        }

        return $content;
    }

    private function detectImageMimeTypeFromContent(string $content): string
    {
        $info = @getimagesizefromstring($content);
        if (is_array($info) && isset($info['mime']) && is_string($info['mime']) && $info['mime'] !== '') {
            return $info['mime'];
        }

        return 'image/jpeg';
    }
}
