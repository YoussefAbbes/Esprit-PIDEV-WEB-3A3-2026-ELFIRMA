<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Reclamation;

final class ReclamationManager
{
    public function validate(Reclamation $reclamation): bool
    {
        $title = $reclamation->getTitreU();
        if ($title !== null && mb_strlen($title) > 100) {
            throw new \InvalidArgumentException('Title must not exceed 100 characters.');
        }

        $description = $reclamation->getDescriptionU();
        if ($description !== null && mb_strlen($description) > 500) {
            throw new \InvalidArgumentException('Description must not exceed 500 characters.');
        }

        return true;
    }
}
