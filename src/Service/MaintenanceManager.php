<?php

namespace App\Service;

use App\Entity\Maintenance;

class MaintenanceManager
{
    public function validate(Maintenance $maintenance): bool
    {

        // type obligatoire
        if (empty($maintenance->getTypeM())) {
            throw new \InvalidArgumentException(
                'Le type de maintenance est obligatoire'
            );
        }

        // coût > 0
        if ($maintenance->getCout() <= 0) {
            throw new \InvalidArgumentException(
                'Le coût doit être supérieur à zéro'
            );
        }

        return true;
    }
}