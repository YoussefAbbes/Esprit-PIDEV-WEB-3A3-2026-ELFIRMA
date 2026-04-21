<?php
namespace App\Service;

use App\Entity\Equipement;

class MaintenancePredictionService
{
    public function generatePrediction(Equipement $equipement): array
    {
        $maintenances = $equipement->getMaintenances()->toArray();

        if (count($maintenances) === 0) {
            return [
                'status' => 'NO_MAINTENANCE',
                'message' => '✅ Cet équipement est en bon état (aucune maintenance enregistrée)'
            ];
        }

        if (count($maintenances) < 2) {
            return [
                'error' => 'Pas assez de données pour prédire'
            ];
        }

        // Trier les dates
        usort($maintenances, fn($a, $b) => $a->getDateM() <=> $b->getDateM());

        $intervals = [];

        for ($i = 1; $i < count($maintenances); $i++) {
            $intervals[] = $maintenances[$i]
                ->getDateM()
                ->diff($maintenances[$i - 1]->getDateM())
                ->days;
        }

        $avgInterval = array_sum($intervals) / count($intervals);

        $lastDate = end($maintenances)->getDateM();

        $predictedDate = (clone $lastDate)->modify("+$avgInterval days");

        $daysRemaining = (new \DateTime())->diff($predictedDate)->days;

        return [
            'predictedDate' => $predictedDate,
            'daysRemaining' => $daysRemaining,
            'riskLevel' => $this->calculateRisk($avgInterval)
        ];
    }

    private function calculateRisk($interval): string
    {
        if ($interval < 10) return "CRITIQUE";
        if ($interval < 30) return "HAUT";
        if ($interval < 60) return "MOYEN";
        return "BAS";
    }
}