<?php

namespace App\Enums;

enum Situation: string
{
    case NonEuEmployee = 'non_eu_employee';
    case EuEmployee = 'eu_employee';
    case Student = 'student';
    case Freelancer = 'freelancer';
    case FamilyReunification = 'family_reunification';
    case DigitalNomad = 'digital_nomad';
    case Other = 'other';
}
