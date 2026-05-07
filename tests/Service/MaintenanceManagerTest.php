<?php

namespace App\Tests\Service;

use PHPUnit\Framework\TestCase;
use App\Entity\Maintenance;
use App\Service\MaintenanceManager;
use App\Enum\MaintenancePriorite;

class MaintenanceManagerTest extends TestCase
{

    // Test valide
    public function testValidMaintenance()
    {
        $maintenance = new Maintenance();

        $maintenance->setTypeM('Réparation');
        $maintenance->setCout(500);
        $maintenance->setPriorite(MaintenancePriorite::HAUTE);

        $manager = new MaintenanceManager();

        $this->assertTrue(
            $manager->validate($maintenance)
        );
    }

    // type vide
    public function testMaintenanceWithoutType()
    {
        $this->expectException(
            \InvalidArgumentException::class
        );

        $maintenance = new Maintenance();

        $maintenance->setCout(200);

        $manager = new MaintenanceManager();

        $manager->validate($maintenance);
    }

    // coût invalide
    public function testMaintenanceWithInvalidCost()
    {
        $this->expectException(
            \InvalidArgumentException::class
        );

        $maintenance = new Maintenance();

        $maintenance->setTypeM('Nettoyage');
        $maintenance->setCout(0);

        $manager = new MaintenanceManager();

        $manager->validate($maintenance);
    }

}