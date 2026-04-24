<?php
namespace App\Controller;

use App\Entity\Equipement;
use App\Service\MaintenancePredictionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;

class PredictionController extends AbstractController
{
    #[Route('/api/predict/{id}', name: 'predict_maintenance')]
    public function predict(
        Equipement $equipement,
        MaintenancePredictionService $service
    ): JsonResponse {

        $result = $service->generatePrediction($equipement);

        return $this->json($result);
    }
}