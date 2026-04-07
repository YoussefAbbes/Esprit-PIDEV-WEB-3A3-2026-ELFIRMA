<?php

namespace App\Enum;

enum MaintenancePriorite: string
{
    case URGENTE = 'urgente';
    case HAUTE = 'haute';
    case MOYENNE = 'moyenne';
    case BASSE = 'basse';
}