<?php

namespace App\Enum;

enum EquipementEtat: string
{
    case DISPONIBLE = 'disponible';
    case MAINTENANCE = 'maintenance';
    case PANNE = 'panne';
}