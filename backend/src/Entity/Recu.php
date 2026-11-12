<?php

namespace App\Entity;

use App\Entity\Contract\AmicaleAwareInterface;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'recu')]
#[ORM\UniqueConstraint(name: 'uniq_recu_numero_amicale', columns: ['amicale_id', 'numero'])]
class Recu implements AmicaleAwareInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Amicale $amicale;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Carnet $carnet;

    #[ORM\Column]
    private int $numero;

    // Keep consumption history even when the passage is corrected or cancelled.
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?Passage $passage = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $utiliseAt = null;

    public function __construct(Carnet $carnet, int $numero)
    {
        if ($numero < $carnet->getPremierRecu() || $numero >= $carnet->getPremierRecu() + $carnet->getNombreRecus()) {
            throw new \InvalidArgumentException('Ce numéro ne fait pas partie du carnet.');
        }
        $this->id = Uuid::v7();
        $this->amicale = $carnet->getAmicale();
        $this->carnet = $carnet;
        $this->numero = $numero;
    }

    public function getId(): Uuid { return $this->id; }
    public function getAmicale(): Amicale { return $this->amicale; }
    public function getCarnet(): Carnet { return $this->carnet; }
    public function getNumero(): int { return $this->numero; }
    public function getPassage(): ?Passage { return $this->passage; }
    public function isUtilise(): bool { return $this->passage !== null; }

    public function utiliser(Passage $passage): void
    {
        if ($this->isUtilise()) {
            throw new \DomainException('Ce reçu a déjà été utilisé. Sélectionnez un reçu disponible.');
        }
        if (!$this->carnet->getTournee()->getId()->equals($passage->getTournee()->getId())) {
            throw new \DomainException('Le reçu appartient à une autre tournée.');
        }
        $this->passage = $passage;
        $this->utiliseAt = new \DateTimeImmutable();
    }
}
