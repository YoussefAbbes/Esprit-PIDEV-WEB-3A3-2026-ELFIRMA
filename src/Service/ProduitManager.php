<?php

namespace App\Service;

use App\Entity\Produit;

class ProduitManager
{
    public function validate(Produit $produit): bool
    {
        // nom obligatoire et max 100 caractères
        $nom = $produit->getNom();
        if (empty($nom)) {
            throw new \InvalidArgumentException(
                'Le nom du produit est obligatoire'
            );
        }

        if (mb_strlen($nom) > 100) {
            throw new \InvalidArgumentException(
                'Le nom du produit ne doit pas dépasser 100 caractères'
            );
        }

        // prix > 0
        if ((float) $produit->getPrixUnitaire() <= 0) {
            throw new \InvalidArgumentException(
                'Le prix unitaire doit être supérieur à zéro'
            );
        }

        return true;
    }
}
