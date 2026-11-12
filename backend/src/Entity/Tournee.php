<?php
namespace App\Entity;

use App\Entity\Contract\AmicaleAwareInterface;
use App\Enum\TourneeStatut;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'tournee')]
#[ORM\Index(name: 'idx_tournee_campagne', columns: ['campagne_id'])]
class Tournee implements AmicaleAwareInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Amicale $amicale;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Secteur $secteur;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Campagne $campagne;

    #[ORM\Column(length: 140)]
    private string $nom;

    #[ORM\Column(enumType: TourneeStatut::class)]
    private TourneeStatut $statut = TourneeStatut::PREPAREE;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $dateDebut;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $dateFin = null;

    #[ORM\Column]
    private int $stockCalendriers = 0;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(Amicale $amicale, Secteur $secteur, string $nom, \DateTimeImmutable $dateDebut, Campagne $campagne)
    {
        if (!$amicale->getId()->equals($secteur->getAmicale()->getId())) {
            throw new \DomainException('Le secteur appartient à une autre amicale.');
        }
        if (!$amicale->getId()->equals($campagne->getAmicale()->getId()) || (int) $dateDebut->format('Y') !== $campagne->getAnnee()) {
            throw new \DomainException('Campagne incohérente avec l’amicale ou la date de la tournée.');
        }
        $this->id = Uuid::v7(); $this->amicale = $amicale; $this->secteur = $secteur;
        $this->nom = $nom; $this->dateDebut = $dateDebut; $this->createdAt = new \DateTimeImmutable();
        $this->campagne = $campagne;
    }
    public function getId(): Uuid { return $this->id; }
    public function getAmicale(): Amicale { return $this->amicale; }
    public function getSecteur(): Secteur { return $this->secteur; }
    public function getCampagne(): Campagne { return $this->campagne; }
    public function getNom(): string { return $this->nom; }
    public function getStatut(): TourneeStatut { return $this->statut; }
    public function setStatut(TourneeStatut $statut): self { $this->statut = $statut; return $this; }
    public function getDateDebut(): \DateTimeImmutable { return $this->dateDebut; }
    public function getStockCalendriers(): int { return $this->stockCalendriers; }
    public function setStockCalendriers(int $stock): self { if ($stock < 0) throw new \InvalidArgumentException('Stock négatif'); $this->stockCalendriers = $stock; return $this; }
}
