<?php

namespace App\Entity;

use App\Entity\Contract\AmicaleAwareInterface;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'carnet')]
#[ORM\UniqueConstraint(name: 'uniq_carnet_numero_amicale', columns: ['amicale_id', 'numero'])]
class Carnet implements AmicaleAwareInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Amicale $amicale;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Tournee $tournee;

    #[ORM\Column(length: 40)]
    private string $numero;

    #[ORM\Column]
    private int $premierRecu;

    #[ORM\Column]
    private int $nombreRecus;

    public function __construct(Tournee $tournee, string $numero, int $premierRecu, int $nombreRecus = 25)
    {
        if (trim($numero) === '' || mb_strlen($numero) > 40 || $premierRecu < 1 || $nombreRecus < 1 || $nombreRecus > 1000 || $premierRecu + $nombreRecus - 1 > 999999999) {
            throw new \InvalidArgumentException('Numéro de carnet ou plage de reçus invalide.');
        }
        $this->id = Uuid::v7();
        $this->amicale = $tournee->getAmicale();
        $this->tournee = $tournee;
        $this->numero = trim($numero);
        $this->premierRecu = $premierRecu;
        $this->nombreRecus = $nombreRecus;
    }

    public function getId(): Uuid { return $this->id; }
    public function getAmicale(): Amicale { return $this->amicale; }
    public function getTournee(): Tournee { return $this->tournee; }
    public function getNumero(): string { return $this->numero; }
    public function getPremierRecu(): int { return $this->premierRecu; }
    public function getNombreRecus(): int { return $this->nombreRecus; }
}
