<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Parcelle;
use App\Service\ParcelleManager;
use PHPUnit\Framework\TestCase;

final class ParcelleManagerTest extends TestCase
{
    public function testValidateWithValidParcelle(): void
    {
        $parcelle = (new Parcelle())
            ->setNom('Parcelle Nord')
            ->setSuperficie(12.5);

        $manager = new ParcelleManager();

        self::assertTrue($manager->validate($parcelle));
    }

    public function testValidateThrowsWhenNomIsMissing(): void
    {
        $parcelle = (new Parcelle())
            ->setSuperficie(7.0);

        $manager = new ParcelleManager();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Le nom de la parcelle est obligatoire.');

        $manager->validate($parcelle);
    }

    public function testValidateThrowsWhenSuperficieIsInvalid(): void
    {
        $parcelle = (new Parcelle())
            ->setNom('Parcelle Sud')
            ->setSuperficie(0.0);

        $manager = new ParcelleManager();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('La superficie doit etre strictement positive.');

        $manager->validate($parcelle);
    }
}
