<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Parcelle;

final class ParcelleManager
{
    public function validate(Parcelle $parcelle): bool
    {
        $nom = $parcelle->getNom();
        if ($nom === null || trim($nom) === '') {
            throw new \InvalidArgumentException('Le nom de la parcelle est obligatoire.');
        }

        $superficie = $parcelle->getSuperficie();
        if ($superficie === null || $superficie <= 0.0) {
            throw new \InvalidArgumentException('La superficie doit etre strictement positive.');
        }

        return true;
    }
}
