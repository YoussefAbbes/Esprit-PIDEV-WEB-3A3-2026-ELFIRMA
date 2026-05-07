<?php

namespace App\Tests\Service;

use App\Entity\Produit;
use App\Service\ProduitManager;
use PHPUnit\Framework\TestCase;

class ProduitManagerTest extends TestCase
{
    // produit valide
    public function testValidProduit()
    {
        $produit = new Produit();

        $produit->setNom('Tomate');
        $produit->setPrixUnitaire('12.50');

        $manager = new ProduitManager();

        $this->assertTrue(
            $manager->validate($produit)
        );
    }

    // nom trop long
    public function testProduitWithNameTooLong()
    {
        $this->expectException(
            \InvalidArgumentException::class
        );

        $produit = new Produit();

        $produit->setNom(str_repeat('a', 101));
        $produit->setPrixUnitaire('10.00');

        $manager = new ProduitManager();

        $manager->validate($produit);
    }

    // prix invalide
    public function testProduitWithInvalidPrice()
    {
        $this->expectException(
            \InvalidArgumentException::class
        );

        $produit = new Produit();

        $produit->setNom('Carotte');
        $produit->setPrixUnitaire('0');

        $manager = new ProduitManager();

        $manager->validate($produit);
    }
}
