<?php

namespace App\Enum;

enum VaccinationStatus: string
{
    case SCHEDULED = 'Scheduled';
    case DONE = 'Done';
    case OVERDUE = 'Overdue';
}