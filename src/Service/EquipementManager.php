<?php

namespace App\Service;

use App\Entity\Equipement;

class EquipementManager
{
    public function validate(Equipement $equipement): bool
    {

        // nom obligatoire
        if (empty($equipement->getNomEq())) {
            throw new \InvalidArgumentException(
                'Le nom de l’équipement est obligatoire'
            );
        }

        // coût > 0
        if ($equipement->getCoutAchat() <= 0) {
            throw new \InvalidArgumentException(
                'Le coût doit être supérieur à zéro'
            );
        }

        // date non future
        if (
            $equipement->getDateAchat() > new \DateTime()
        ) {
            throw new \InvalidArgumentException(
                'La date d’achat ne peut pas être future'
            );
        }

        return true;
    }
}