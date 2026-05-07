<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Animal;

final class AnimalManager
{
    public function validate(Animal $animal): bool
    {
        $typeAnimal = $animal->getTypeAnimal();
        if ($typeAnimal === null || trim($typeAnimal) === '') {
            throw new \InvalidArgumentException('Type is required.');
        }

        if (!preg_match('/^[\p{L}\s]+$/u', $typeAnimal)) {
            throw new \InvalidArgumentException('Type can contain letters and spaces only.');
        }

        $age = $animal->getAge();
        if ($age === null) {
            throw new \InvalidArgumentException('Age is required.');
        }

        if ($age < 0) {
            throw new \InvalidArgumentException('Age must be greater than or equal to 0.');
        }

        return true;
    }
}
