<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Reclamation;
use App\Service\ReclamationManager;
use PHPUnit\Framework\TestCase;

final class ReclamationManagerTest extends TestCase
{
    public function testValidateWithValidTitleAndDescriptionLengths(): void
    {
        $reclamation = (new Reclamation())
            ->setTitreU(str_repeat('A', 100))
            ->setDescriptionU(str_repeat('B', 500));

        $manager = new ReclamationManager();

        self::assertTrue($manager->validate($reclamation));
    }

    public function testValidateThrowsWhenTitleExceeds100Characters(): void
    {
        $reclamation = (new Reclamation())
            ->setTitreU(str_repeat('A', 101))
            ->setDescriptionU('Description valide');

        $manager = new ReclamationManager();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Title must not exceed 100 characters.');

        $manager->validate($reclamation);
    }

    public function testValidateThrowsWhenDescriptionExceeds500Characters(): void
    {
        $reclamation = (new Reclamation())
            ->setTitreU('Titre valide')
            ->setDescriptionU(str_repeat('B', 501));

        $manager = new ReclamationManager();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Description must not exceed 500 characters.');

        $manager->validate($reclamation);
    }
}
