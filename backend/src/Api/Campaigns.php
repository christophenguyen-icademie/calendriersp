<?php

namespace App\Api;

use App\Entity\{Amicale, Campagne, Tournee};
use Doctrine\ORM\EntityManagerInterface;

final class Campaigns
{
    public function __construct(private EntityManagerInterface $em) {}

    /** Caller locks the amicale in its transaction, also shared with deletion. */
    public function ensure(Amicale $amicale, int $year): Campagne
    {
        $campaign = $this->em->getRepository(Campagne::class)->findOneBy(['amicale' => $amicale, 'annee' => $year]);
        if (!$campaign) { $campaign = new Campagne($amicale, $year); $this->em->persist($campaign); }
        return $campaign;
    }

    public function data(Campagne $campaign): array
    {
        return ['id' => (string) $campaign->getId(), 'annee' => $campaign->getAnnee(),
            'tournees' => $this->em->getRepository(Tournee::class)->count(['campagne' => $campaign]),
            'parDefaut' => $campaign->getAnnee() === ($campaign->getAmicale()->getParametres()['campagne'] ?? (int) date('Y'))];
    }
}
