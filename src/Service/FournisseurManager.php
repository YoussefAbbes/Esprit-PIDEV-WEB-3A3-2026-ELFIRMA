<?php

namespace App\Service;

use App\Entity\Fournisseur;

class FournisseurManager
{
    public function validate(Fournisseur $fournisseur): bool
    {

        // type obligatoire
        if (empty($fournisseur->getTypeF())) {
            throw new \InvalidArgumentException(
                'Le type du fournisseur est obligatoire'
            );
        }

        // téléphone : 8 chiffres
        if (
            !preg_match(
                '/^\d{8}$/',
                $fournisseur->getTelF()
            )
        ) {
            throw new \InvalidArgumentException(
                'Telephone invalide'
            );
        }

        // email valide
        if (
            !filter_var(
                $fournisseur->getEmailF(),
                FILTER_VALIDATE_EMAIL
            )
        ) {
            throw new \InvalidArgumentException(
                'Email invalide'
            );
        }

        // adresse contient au moins une lettre
        if (
            !preg_match(
                '/[a-zA-Z]/',
                $fournisseur->getAdresseF()
            )
        ) {
            throw new \InvalidArgumentException(
                'Adresse invalide'
            );
        }

        return true;
    }
}