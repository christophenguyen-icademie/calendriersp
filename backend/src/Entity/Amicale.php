<?php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'amicale')]
#[ORM\UniqueConstraint(name: 'uniq_amicale_identifiant', columns: ['identifiant'])]
class Amicale
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(length: 20)]
    private string $identifiant;

    #[ORM\Column(length: 180)]
    private string $nom;

    #[ORM\Column(length: 3)]
    private string $departement;

    #[ORM\Column(type: 'json')]
    private array $parametres = [];

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $nom, string $departement, string $identifiant)
    {
        $this->id = Uuid::v7();
        $this->setIdentifiant($identifiant);
        $this->nom = $nom;
        $this->departement = $departement;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid { return $this->id; }
    public function getIdentifiant(): string { return $this->identifiant; }
    public function setIdentifiant(string $identifiant): self { $this->identifiant = self::normaliserIdentifiant($identifiant); return $this; }
    public static function normaliserIdentifiant(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^[a-zA-Z0-9]{4,20}$/D', trim($value))) {
            throw new \InvalidArgumentException('L’identifiant d’amicale doit contenir de 4 à 20 lettres non accentuées ou chiffres, sans espace ni signe.');
        }
        return strtolower(trim($value));
    }
    public function getNom(): string { return $this->nom; }
    public function setNom(string $nom): self { $this->nom = $nom; return $this; }
    public function getDepartement(): string { return $this->departement; }
    public function setDepartement(string $departement): self { $this->departement = $departement; return $this; }
    public function getParametres(): array { return $this->parametres; }
    public function setParametres(array $parametres): self { $this->parametres = $parametres; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
