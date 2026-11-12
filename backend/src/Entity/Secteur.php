<?php
namespace App\Entity;

use App\Entity\Contract\AmicaleAwareInterface;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'secteur')]
class Secteur implements AmicaleAwareInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Amicale $amicale;

    #[ORM\Column(length: 120)]
    private string $nom;

    #[ORM\Column(length: 30)]
    private string $type = 'LIBRE';

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $polygone = null;

    public function __construct(Amicale $amicale, string $nom, string $type = 'LIBRE')
    {
        $this->id = Uuid::v7(); $this->amicale = $amicale; $this->nom = $nom; $this->type = $type;
    }
    public function getId(): Uuid { return $this->id; }
    public function getAmicale(): Amicale { return $this->amicale; }
    public function getNom(): string { return $this->nom; }
    public function setNom(string $nom): self { $this->nom = $nom; return $this; }
    public function getType(): string { return $this->type; }
    public function setType(string $type): self { $this->type = $type; return $this; }
    public function getPolygone(): ?array { return $this->polygone; }
    public function setPolygone(?array $polygone): self { $this->polygone = $polygone; return $this; }
}
