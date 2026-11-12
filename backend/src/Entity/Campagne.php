<?php

namespace App\Entity;

use App\Entity\Contract\AmicaleAwareInterface;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'campagne')]
#[ORM\UniqueConstraint(name: 'uniq_campagne_amicale_annee', columns: ['amicale_id', 'annee'])]
#[ORM\Index(name: 'idx_campagne_amicale', columns: ['amicale_id'])]
class Campagne implements AmicaleAwareInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Amicale $amicale;

    #[ORM\Column]
    private int $annee;

    public function __construct(Amicale $amicale, int $annee)
    {
        if ($annee < 2000 || $annee > 2100) { throw new \InvalidArgumentException('Année de campagne invalide (2000 à 2100).'); }
        $this->id = Uuid::v7(); $this->amicale = $amicale; $this->annee = $annee;
    }
    public function getId(): Uuid { return $this->id; }
    public function getAmicale(): Amicale { return $this->amicale; }
    public function getAnnee(): int { return $this->annee; }
}
