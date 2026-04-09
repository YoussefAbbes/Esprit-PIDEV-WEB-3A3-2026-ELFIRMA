<?php

namespace App\Enum;

enum MaintenanceStatut: string
{
    case PLANIFIE = 'planifie';
    case ENCOURS = 'en_cours';
    case TERMINE = 'termine';
}