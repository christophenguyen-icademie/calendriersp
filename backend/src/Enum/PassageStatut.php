<?php
namespace App\Enum;

enum PassageStatut: string
{
    case NON_VISITEE = 'NON_VISITEE';
    case VENTE = 'VENTE';
    case DON = 'DON';
    case REFUS = 'REFUS';
    case ABSENT = 'ABSENT';
    case A_REVOIR = 'A_REVOIR';
}
