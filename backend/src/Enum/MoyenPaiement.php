<?php
namespace App\Enum;

enum MoyenPaiement: string
{
    case ESPECES = 'ESPECES';
    case CHEQUE = 'CHEQUE';
    case CB = 'CB';
    case AUTRE = 'AUTRE';
}
