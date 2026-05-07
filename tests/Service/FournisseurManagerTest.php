<?php

namespace App\Tests\Service;

use PHPUnit\Framework\TestCase;
use App\Entity\Fournisseur;
use App\Service\FournisseurManager;

class FournisseurManagerTest extends TestCase
{

    // fournisseur valide
    public function testValidFournisseur()
    {
        $fournisseur = new Fournisseur();

        $fournisseur->setTypeF('Agricole');

        $fournisseur->setAdresseF(
            '123 Marsa'
        );

        $fournisseur->setTelF('12345678');

        $fournisseur->setEmailF(
            'test@gmail.com'
        );

        $manager = new FournisseurManager();

        $this->assertTrue(
            $manager->validate($fournisseur)
        );
    }

    // type vide
    public function testFournisseurWithoutType()
    {
        $this->expectException(
            \InvalidArgumentException::class
        );

        $fournisseur = new Fournisseur();

        $fournisseur->setAdresseF('Marsa');
        $fournisseur->setTelF('12345678');
        $fournisseur->setEmailF('test@gmail.com');

        $manager = new FournisseurManager();

        $manager->validate($fournisseur);
    }

    // téléphone invalide
    public function testFournisseurWithInvalidPhone()
    {
        $this->expectException(
            \InvalidArgumentException::class
        );

        $fournisseur = new Fournisseur();

        $fournisseur->setTypeF('Agricole');
        $fournisseur->setAdresseF('Marsa');

        // mauvais téléphone
        $fournisseur->setTelF('123');

        $fournisseur->setEmailF(
            'test@gmail.com'
        );

        $manager = new FournisseurManager();

        $manager->validate($fournisseur);
    }

    // email invalide
    public function testFournisseurWithInvalidEmail()
    {
        $this->expectException(
            \InvalidArgumentException::class
        );

        $fournisseur = new Fournisseur();

        $fournisseur->setTypeF('Agricole');
        $fournisseur->setAdresseF('Marsa');
        $fournisseur->setTelF('12345678');

        // mauvais email
        $fournisseur->setEmailF(
            'email_invalide'
        );

        $manager = new FournisseurManager();

        $manager->validate($fournisseur);
    }

    // adresse invalide
    public function testFournisseurWithInvalidAddress()
    {
        $this->expectException(
            \InvalidArgumentException::class
        );

        $fournisseur = new Fournisseur();

        $fournisseur->setTypeF('Agricole');

        // uniquement chiffres
        $fournisseur->setAdresseF('123456');

        $fournisseur->setTelF('12345678');

        $fournisseur->setEmailF(
            'test@gmail.com'
        );

        $manager = new FournisseurManager();

        $manager->validate($fournisseur);
    }
}