<?php
namespace App\Entity;

use App\Enum\MoyenPaiement;
use App\Enum\PassageStatut;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'passage')]
#[ORM\UniqueConstraint(name: 'uniq_passage_tournee_adresse', columns: ['tournee_id', 'adresse_id'])]
class Passage
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Tournee $tournee;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Adresse $adresse;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $saisiPar;

    #[ORM\Column(enumType: PassageStatut::class)]
    private PassageStatut $statut = PassageStatut::NON_VISITEE;

    #[ORM\Column]
    private int $nbCalendriers = 0;

    #[ORM\Column(type: 'decimal', precision: 9, scale: 2)]
    private string $montant = '0.00';

    #[ORM\Column(enumType: MoyenPaiement::class, nullable: true)]
    private ?MoyenPaiement $moyenPaiement = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $commentaire = null;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?Recu $recu = null;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $datePassage;

    public function __construct(Tournee $tournee, Adresse $adresse, User $saisiPar)
    {
        if ($tournee->getAmicale()->getId()->toRfc4122() !== $saisiPar->getAmicale()->getId()->toRfc4122()
            || !$tournee->getSecteur()->getId()->equals($adresse->getSecteur()->getId())) {
            throw new \DomainException('Incohérence d’amicale sur le passage.');
        }
        $this->id = Uuid::v7(); $this->tournee = $tournee; $this->adresse = $adresse;
        $this->saisiPar = $saisiPar; $this->updatedAt = new \DateTimeImmutable();
        $this->datePassage = new \DateTimeImmutable('today', new \DateTimeZone('Europe/Paris'));
    }

    public function getId(): Uuid { return $this->id; }
    public function getStatut(): PassageStatut { return $this->statut; }
    public function getTournee(): Tournee { return $this->tournee; }
    public function getAdresse(): Adresse { return $this->adresse; }
    public function getNbCalendriers(): int { return $this->nbCalendriers; }
    public function getMontant(): string { return $this->montant; }
    public function getMoyenPaiement(): ?MoyenPaiement { return $this->moyenPaiement; }
    public function getCommentaire(): ?string { return $this->commentaire; }
    public function getRecu(): ?Recu { return $this->recu; }
    public function getDatePassage(): \DateTimeImmutable { return $this->datePassage; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function enregistrer(PassageStatut $statut, int $nb, string $montant, ?MoyenPaiement $paiement, ?string $commentaire, ?Recu $recu = null, ?\DateTimeImmutable $datePassage = null): self
    {
        if ($this->tournee->getStatut() === \App\Enum\TourneeStatut::VALIDEE) {
            throw new \DomainException('La tournée est validée et ne peut plus être modifiée.');
        }
        if ($nb < 0 || $nb > 100000 || !preg_match('/^\d{1,7}(\.\d{1,2})?$/D', $montant)) {
            throw new \InvalidArgumentException('Quantité ou montant invalide.');
        }
        if ($statut === PassageStatut::VENTE && ($nb < 1 || $paiement === null)) {
            throw new \InvalidArgumentException('Une vente exige une quantité positive et un moyen de paiement.');
        }
        if ($statut !== PassageStatut::VENTE && ($nb !== 0 || preg_match('/[1-9]/', $montant) || $paiement !== null)) {
            throw new \InvalidArgumentException('Seule une vente peut contenir une quantité, un montant ou un moyen de paiement.');
        }
        $exigeRecu = in_array($statut, [PassageStatut::VENTE, PassageStatut::DON], true);
        if ($exigeRecu && $recu === null) {
            throw new \InvalidArgumentException('Un carnet et un reçu sont obligatoires pour une vente ou un don.');
        }
        if (!$exigeRecu && $recu !== null) {
            throw new \InvalidArgumentException('Seuls une vente ou un don peuvent utiliser un reçu.');
        }
        if ($recu !== null && !$recu->getCarnet()->getTournee()->getId()->equals($this->tournee->getId())) {
            throw new \DomainException('Le reçu appartient à une autre tournée.');
        }
        if ($recu !== null && ($this->recu === null || !$this->recu->getId()->equals($recu->getId()))) {
            $recu->utiliser($this);
        }
        [$entier, $decimales] = array_pad(explode('.', $montant, 2), 2, '');
        $this->statut = $statut; $this->nbCalendriers = $nb;
        $this->montant = (string) (int) $entier.'.'.str_pad($decimales, 2, '0');
        $this->moyenPaiement = $paiement; $this->commentaire = $commentaire; $this->updatedAt = new \DateTimeImmutable();
        if ($datePassage !== null) { $this->datePassage = $datePassage; }
        $this->recu = $recu;
        return $this;
    }
}
