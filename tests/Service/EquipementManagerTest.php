<?php

namespace App\Tests\Service;

use PHPUnit\Framework\TestCase;
use App\Entity\Equipement;
use App\Service\EquipementManager;
use App\Enum\EquipementEtat;

class EquipementManagerTest extends TestCase
{

    // équipement valide
    public function testValidEquipement()
    {
        $equipement = new Equipement();

        $equipement->setNomEq('Tracteur');
        $equipement->setTypeEq('Agricole');

        $equipement->setEtat(
            EquipementEtat::DISPONIBLE
        );

        $equipement->setCoutAchat(50000);

        $equipement->setDateAchat(
            new \DateTime('2024-01-10')
        );

        $manager = new EquipementManager();

        $this->assertTrue(
            $manager->validate($equipement)
        );
    }

    // nom vide
    public function testEquipementWithoutName()
    {
        $this->expectException(
            \InvalidArgumentException::class
        );

        $equipement = new Equipement();

        $equipement->setCoutAchat(1000);

        $manager = new EquipementManager();

        $manager->validate($equipement);
    }

    // coût invalide
    public function testEquipementWithInvalidCost()
    {
        $this->expectException(
            \InvalidArgumentException::class
        );

        $equipement = new Equipement();

        $equipement->setNomEq('Moissonneuse');
        $equipement->setCoutAchat(0);

        $manager = new EquipementManager();

        $manager->validate($equipement);
    }

    // date future
    public function testEquipementWithFutureDate()
    {
        $this->expectException(
            \InvalidArgumentException::class
        );

        $equipement = new Equipement();

        $equipement->setNomEq('Drone');

        $equipement->setEtat(
            EquipementEtat::DISPONIBLE
        );

        $equipement->setCoutAchat(3000);

        $equipement->setDateAchat(
            new \DateTime('2035-01-01')
        );

        $manager = new EquipementManager();

        $manager->validate($equipement);
    }
}