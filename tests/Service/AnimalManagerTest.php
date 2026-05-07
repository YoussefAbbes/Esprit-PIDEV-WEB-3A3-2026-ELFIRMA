<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Animal;
use App\Service\AnimalManager;
use PHPUnit\Framework\TestCase;

final class AnimalManagerTest extends TestCase
{
    public function testValidateWithValidAnimal(): void
    {
        $animal = (new Animal())
            ->setTypeAnimal('Bovin')
            ->setAge(3);

        $manager = new AnimalManager();

        self::assertTrue($manager->validate($animal));
    }

    public function testValidateThrowsWhenTypeIsMissing(): void
    {
        $animal = (new Animal())
            ->setAge(2);

        $manager = new AnimalManager();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Type is required.');

        $manager->validate($animal);
    }

    public function testValidateThrowsWhenTypeContainsInvalidCharacters(): void
    {
        $animal = (new Animal())
            ->setTypeAnimal('Bovin123')
            ->setAge(2);

        $manager = new AnimalManager();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Type can contain letters and spaces only.');

        $manager->validate($animal);
    }

    public function testValidateThrowsWhenAgeIsNull(): void
    {
        $animal = (new Animal())
            ->setTypeAnimal('Ovin');

        $manager = new AnimalManager();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Age is required.');

        $manager->validate($animal);
    }

    public function testValidateThrowsWhenAgeIsNegative(): void
    {
        $animal = (new Animal())
            ->setTypeAnimal('Ovin')
            ->setAge(-1);

        $manager = new AnimalManager();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Age must be greater than or equal to 0.');

        $manager->validate($animal);
    }
}
