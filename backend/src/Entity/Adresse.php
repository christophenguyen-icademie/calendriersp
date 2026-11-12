<?php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'adresse')]
#[ORM\UniqueConstraint(name: 'uniq_adresse_source_secteur', columns: ['secteur_id', 'source_id'])]
#[ORM\UniqueConstraint(name: 'uniq_adresse_identite_secteur', columns: ['secteur_id', 'identite'])]
class Adresse
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Secteur $secteur;

    #[ORM\Column(length: 180)]
    private string $ligne1;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $complement = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $batiment = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $etage = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $appartement = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $porte = null;

    #[ORM\Column(length: 10)]
    private string $codePostal;

    #[ORM\Column(length: 120)]
    private string $ville;

    #[ORM\Column(nullable: true)]
    private ?float $latitude = null;

    #[ORM\Column(nullable: true)]
    private ?float $longitude = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $consigne = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $sourceId = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $identite = null;

    public function __construct(Secteur $secteur, string $ligne1, string $codePostal, string $ville)
    {
        $this->id = Uuid::v7(); $this->secteur = $secteur; $this->ligne1 = $ligne1;
        $this->codePostal = $codePostal; $this->ville = $ville;
        $this->identite = self::identity($ligne1, $codePostal, $ville);
    }
    public function getId(): Uuid { return $this->id; }
    public function getSecteur(): Secteur { return $this->secteur; }
    public function getLigne1(): string { return $this->ligne1; }
    public function getCodePostal(): string { return $this->codePostal; }
    public function getVille(): string { return $this->ville; }
    public function getLatitude(): ?float { return $this->latitude; }
    public function getLongitude(): ?float { return $this->longitude; }
    public function getSourceId(): ?string { return $this->sourceId; }
    public function getComplement(): ?string { return $this->complement; }
    public function getLogement(): array
    {
        return ['batiment' => $this->batiment, 'etage' => $this->etage, 'appartement' => $this->appartement, 'porte' => $this->porte];
    }
    public function setLogement(array $logement): self
    {
        $before = $this->getLogement();
        foreach (array_keys($this->getLogement()) as $key) {
            $value = $logement[$key] ?? null;
            if ($value !== null && (!is_string($value) || mb_strlen(trim($value)) > 80)) {
                throw new \InvalidArgumentException("Le champ $key doit contenir au maximum 80 caractères.");
            }
            $this->$key = $value === null || trim($value) === '' ? null : trim($value);
        }
        if ($before !== $this->getLogement()) {
            $this->identite = self::identity($this->ligne1, $this->codePostal, $this->ville, $this->getLogement());
        }
        return $this;
    }
    public function dupliquer(array $logement): self
    {
        $copy = new self($this->secteur, $this->ligne1, $this->codePostal, $this->ville);
        $copy->latitude = $this->latitude; $copy->longitude = $this->longitude;
        $copy->complement = $this->complement; $copy->consigne = $this->consigne;
        $copy->setLogement($logement);
        if ($copy->identite === self::identity($this->ligne1, $this->codePostal, $this->ville, $this->getLogement())
            || !array_filter($copy->getLogement(), static fn ($value) => $value !== null)) {
            throw new \InvalidArgumentException('Précisez un logement différent (bâtiment, étage, appartement ou porte).');
        }
        // A source ID identifies the imported street address, never its individual apartments.
        return $copy;
    }
    public function setSourceId(string $sourceId): self { $this->sourceId = $sourceId; return $this; }
    public function setPosition(float $latitude, float $longitude): self
    {
        if (!is_finite($latitude) || !is_finite($longitude) || abs($latitude) > 90 || abs($longitude) > 180) {
            throw new \InvalidArgumentException('Coordonnées invalides.');
        }
        $this->latitude = $latitude; $this->longitude = $longitude;
        return $this;
    }

    public static function identity(string $ligne1, string $codePostal, string $ville, array $logement = []): string
    {
        $normalise = static fn (string $value): string => preg_replace('/[^\p{L}\p{N}]/u', '', mb_strtolower(\Normalizer::normalize($value)));
        $parts = [$ligne1, $codePostal, $ville];
        $details = array_map(static fn ($key) => $normalise($logement[$key] ?? ''), ['batiment', 'etage', 'appartement', 'porte']);
        // Keep existing base-address hashes stable for geographical import deduplication.
        if (implode('', $details) !== '') { $parts = [...$parts, ...$details]; }
        return hash('sha256', implode('|', array_map($normalise, $parts)));
    }
}
