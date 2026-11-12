<?php
namespace App\Entity\Contract;

use App\Entity\Amicale;

interface AmicaleAwareInterface
{
    public function getAmicale(): Amicale;
}
