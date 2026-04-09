<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Parcelle;
use App\Form\ParcelleType;
use App\Repository\ParcelleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Form\FormError;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/elfirma/parcelles')]
final class ParcelleController extends AbstractController
{
    #[Route('', name: 'parcelle_index', methods: ['GET'])]
    public function index(Request $request, ParcelleRepository $parcelleRepository): Response
    {
        $page = max(1, $request->query->getInt('page', 1));
        $limit = $request->query->getInt('limit', 10);
        $search = $request->query->get('search', '');
        $sortBy = $request->query->get('sort', 'id');
        $sortOrder = $request->query->get('order', 'DESC');

        $result = $parcelleRepository->findPaginated($page, $limit, $search ?: null, $sortBy, $sortOrder);

        return $this->render('elfirma/parcelles/index.html.twig', [
            'parcelles' => $result['data'],
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

    #[Route('/new', name: 'parcelle_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $parcelle = new Parcelle();
        $form = $this->createForm(ParcelleType::class, $parcelle);
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
                    $parcelle->setImage($content);
                }
            }

            if ($form->getErrors(true)->count() > 0) {
                return $this->render('elfirma/parcelles/new.html.twig', [
                    'parcelle' => $parcelle,
                    'form' => $form,
                ]);
            }

            try {
                $entityManager->persist($parcelle);
                $entityManager->flush();

                $this->addFlash('success', 'Parcel created successfully!');

                return $this->redirectToRoute('parcelle_index');
            } catch (\Throwable $e) {
                $form->addError(new FormError('Unable to save parcel. Please check highlighted fields and try again.'));
            }
        }

        return $this->render('elfirma/parcelles/new.html.twig', [
            'parcelle' => $parcelle,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'parcelle_show', methods: ['GET'])]
    public function show(Parcelle $parcelle): Response
    {
        return $this->render('elfirma/parcelles/show.html.twig', [
            'parcelle' => $parcelle,
        ]);
    }

    #[Route('/{id}/edit', name: 'parcelle_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Parcelle $parcelle, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(ParcelleType::class, $parcelle);
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
                    $parcelle->setImage($content);
                }
            }

            if ($form->getErrors(true)->count() > 0) {
                return $this->render('elfirma/parcelles/edit.html.twig', [
                    'parcelle' => $parcelle,
                    'form' => $form,
                ]);
            }

            try {
                $entityManager->flush();

                $this->addFlash('success', 'Parcel updated successfully!');

                return $this->redirectToRoute('parcelle_index');
            } catch (\Throwable $e) {
                $form->addError(new FormError('Unable to update parcel. Please check highlighted fields and try again.'));
            }
        }

        return $this->render('elfirma/parcelles/edit.html.twig', [
            'parcelle' => $parcelle,
            'form' => $form,
        ]);
    }

    #[Route('/{id}/delete', name: 'parcelle_delete', methods: ['POST'])]
    public function delete(Request $request, Parcelle $parcelle, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete' . $parcelle->getId(), $request->request->get('_token'))) {
            $entityManager->remove($parcelle);
            $entityManager->flush();

            $this->addFlash('success', 'Parcel deleted successfully!');
        }

        return $this->redirectToRoute('parcelle_index');
    }

    #[Route('/{id}/image', name: 'parcelle_image', methods: ['GET'])]
    public function image(Parcelle $parcelle): Response
    {
        $blob = $parcelle->getImage();
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
