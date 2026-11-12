<?php
namespace App\Enum;

enum TourneeStatut: string
{
    case PREPAREE = 'PREPAREE';
    case EN_COURS = 'EN_COURS';
    case TERMINEE = 'TERMINEE';
    case VALIDEE = 'VALIDEE';
}
