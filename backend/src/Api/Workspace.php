<?php

namespace App\Api;

use App\Entity\{Adresse, Carnet, Passage, Recu, Secteur, Tournee, TourneeAffectation, User};
use App\Enum\PassageStatut;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\{AccessDeniedHttpException, NotFoundHttpException};
use Symfony\Component\Uid\Uuid;

/** Queries and explicit response DTOs. No unfiltered entity is exposed to clients. */
final class Workspace
{
    public function __construct(public readonly EntityManagerInterface $em, private Security $security) {}

    public function user(): User
    {
        $user = $this->security->getUser();
        if (!$user instanceof User || !$user->isActive()) {
            throw new AccessDeniedHttpException('Compte indisponible.');
        }
        return $user;
    }

    public function isManager(): bool
    {
        return (bool) array_intersect($this->user()->getRoles(), ['ROLE_ADMIN_AMICALE', 'ROLE_RESPONSABLE']);
    }

    public function requireManager(): void
    {
        if (!$this->isManager()) {
            throw new AccessDeniedHttpException('Accès réservé à la gestion de l’amicale.');
        }
    }

    public function requireAdmin(): void
    {
        if (!in_array('ROLE_ADMIN_AMICALE', $this->user()->getRoles(), true)) {
            throw new AccessDeniedHttpException('Accès réservé à l’administrateur de l’amicale.');
        }
    }

    public function profile(): array
    {
        $user = $this->user();
        $amicale = $user->getAmicale();
        return ['id' => (string) $user->getId(), 'email' => $user->getEmail(), 'nom' => $user->getNom(), 'roles' => $user->getRoles(), 'canManage' => $this->isManager(),
            'amicale' => ['id' => (string) $amicale->getId(), 'identifiant' => $amicale->getIdentifiant(), 'nom' => $amicale->getNom(), 'departement' => $amicale->getDepartement(),
                'campagne' => $amicale->getParametres()['campagne'] ?? (int) date('Y')]];
    }

    public function secteurs(): array
    {
        return $this->em->getRepository(Secteur::class)->findBy(['amicale' => $this->user()->getAmicale()], ['nom' => 'ASC']);
    }

    public function users(): array
    {
        return $this->em->getRepository(User::class)->findBy(['amicale' => $this->user()->getAmicale()], ['nom' => 'ASC', 'email' => 'ASC']);
    }

    public function adresses(?Secteur $secteur = null): array
    {
        $qb = $this->em->createQueryBuilder()->select('a', 's')->from(Adresse::class, 'a')->join('a.secteur', 's')
            ->where('s.amicale = :amicale')->setParameter('amicale', $this->user()->getAmicale())->orderBy('a.ligne1', 'ASC');
        if ($secteur) {
            $qb->andWhere('a.secteur = :secteur')->setParameter('secteur', $secteur);
        }
        return $qb->getQuery()->getResult();
    }

    public function tournees(?int $year = null): array
    {
        $qb = $this->em->createQueryBuilder()->select('t', 's')->from(Tournee::class, 't')->join('t.secteur', 's')
            ->where('t.amicale = :amicale')->setParameter('amicale', $this->user()->getAmicale())->orderBy('t.dateDebut', 'DESC');
        if (!$this->isManager()) {
            $qb->join(TourneeAffectation::class, 'aff', 'WITH', 'aff.tournee = t AND aff.user = :user')->setParameter('user', $this->user());
        }
        if ($year !== null) {
            $qb->andWhere('t.dateDebut >= :start AND t.dateDebut < :end')
                ->setParameter('start', new \DateTimeImmutable("$year-01-01"))->setParameter('end', new \DateTimeImmutable(($year + 1).'-01-01'));
        }
        return $qb->getQuery()->getResult();
    }

    public function owned(string $class, string $id): object
    {
        if (!Uuid::isValid($id)) {
            throw new NotFoundHttpException('Élément introuvable.');
        }
        // Scope the database query itself, including indirect tenant relationships.
        $qb = $this->em->createQueryBuilder()->select('e')->from($class, 'e')->where('e.id = :id')->setParameter('id', Uuid::fromString($id));
        if ($class === Adresse::class) {
            $qb->join('e.secteur', 's')->andWhere('s.amicale = :amicale');
        } elseif ($class === Passage::class) {
            $qb->join('e.tournee', 't')->andWhere('t.amicale = :amicale');
        } else {
            $qb->andWhere('e.amicale = :amicale');
        }
        $qb->setParameter('amicale', $this->user()->getAmicale());
        if ($class === Tournee::class && !$this->isManager()) {
            $qb->join(TourneeAffectation::class, 'aff', 'WITH', 'aff.tournee = e AND aff.user = :user')->setParameter('user', $this->user());
        }
        return $qb->getQuery()->getOneOrNullResult() ?? throw new NotFoundHttpException('Élément introuvable.');
    }

    public function secteurData(Secteur $s): array
    {
        return ['id' => (string) $s->getId(), 'nom' => $s->getNom(), 'type' => $s->getType(), 'polygone' => $s->getPolygone()];
    }

    public function userData(User $u): array
    {
        return ['id' => (string) $u->getId(), 'email' => $u->getEmail(), 'nom' => $u->getNom(), 'roles' => $u->getRoles(), 'active' => $u->isActive()];
    }

    public function adresseData(Adresse $a): array
    {
        return ['id' => (string) $a->getId(), 'ligne1' => $a->getLigne1(), 'codePostal' => $a->getCodePostal(), 'ville' => $a->getVille(),
            'secteurId' => (string) $a->getSecteur()->getId(), 'secteur' => $a->getSecteur()->getNom(), 'latitude' => $a->getLatitude(), 'longitude' => $a->getLongitude(), 'sourceId' => $a->getSourceId(),
            'complement' => $a->getComplement(), ...$a->getLogement()];
    }

    public function passageData(Passage $p): array
    {
        $recu = $p->getRecu();
        return ['id' => (string) $p->getId(), 'adresseId' => (string) $p->getAdresse()->getId(), 'statut' => $p->getStatut()->value,
            'nbCalendriers' => $p->getNbCalendriers(), 'montant' => $p->getMontant(), 'moyenPaiement' => $p->getMoyenPaiement()?->value, 'commentaire' => $p->getCommentaire(),
            'datePassage' => $p->getDatePassage()->format('Y-m-d'), 'updatedAt' => $p->getUpdatedAt()->format(DATE_ATOM),
            'recu' => $recu ? ['id' => (string) $recu->getId(), 'numero' => $recu->getNumero(), 'carnetId' => (string) $recu->getCarnet()->getId(), 'carnetNumero' => $recu->getCarnet()->getNumero()] : null];
    }

    public function carnets(Tournee $tournee): array
    {
        return $this->em->getRepository(Carnet::class)->findBy(['amicale' => $this->user()->getAmicale(), 'tournee' => $tournee], ['numero' => 'ASC']);
    }

    public function carnetData(Carnet $carnet, bool $detail = false): array
    {
        $recus = $this->em->getRepository(Recu::class)->findBy(['carnet' => $carnet], ['numero' => 'ASC']);
        $utilises = $ventes = $dons = $annules = $montant = 0;
        $liste = [];
        foreach ($recus as $recu) {
            $p = $recu->getPassage();
            $actif = $p !== null && $p->getRecu()?->getId()->equals($recu->getId());
            $utilises += $recu->isUtilise() ? 1 : 0;
            $annules += $recu->isUtilise() && !$actif ? 1 : 0;
            if ($actif) {
                $ventes += $p->getStatut() === PassageStatut::VENTE ? 1 : 0;
                $dons += $p->getStatut() === PassageStatut::DON ? 1 : 0;
                $montant += self::cents($p->getMontant());
            }
            if ($detail) {
                $liste[] = ['id' => (string) $recu->getId(), 'numero' => $recu->getNumero(), 'utilise' => $recu->isUtilise(), 'annule' => $recu->isUtilise() && !$actif];
            }
        }
        $result = ['id' => (string) $carnet->getId(), 'numero' => $carnet->getNumero(), 'tourneeId' => (string) $carnet->getTournee()->getId(), 'tournee' => $carnet->getTournee()->getNom(),
            'premierRecu' => $carnet->getPremierRecu(), 'dernierRecu' => $carnet->getPremierRecu() + $carnet->getNombreRecus() - 1,
            'nombreRecus' => count($recus), 'recusUtilises' => $utilises, 'recusDisponibles' => count($recus) - $utilises,
            'ventes' => $ventes, 'dons' => $dons, 'recusAnnules' => $annules, 'montant' => self::decimal($montant)];
        if ($detail) {
            $result['recus'] = $liste;
        }
        return $result;
    }

    public static function cents(string $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }

    public static function decimal(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    public function tourneeData(Tournee $t, bool $detail = false): array
    {
        $adresses = $this->adresses($t->getSecteur());
        $passages = $this->em->getRepository(Passage::class)->findBy(['tournee' => $t]);
        $affectations = $this->em->getRepository(TourneeAffectation::class)->findBy(['tournee' => $t]);
        $traitees = $calendriers = $dons = $total = 0;
        $paiements = array_fill_keys(array_column(\App\Enum\MoyenPaiement::cases(), 'value'), 0);
        foreach ($passages as $p) {
            $traitees += $p->getStatut() !== PassageStatut::NON_VISITEE ? 1 : 0;
            $calendriers += $p->getNbCalendriers();
            $dons += $p->getStatut() === PassageStatut::DON ? 1 : 0;
            $total += self::cents($p->getMontant());
            if ($p->getMoyenPaiement()) {
                $paiements[$p->getMoyenPaiement()->value] += self::cents($p->getMontant());
            }
        }
        $result = ['id' => (string) $t->getId(), 'nom' => $t->getNom(), 'secteurId' => (string) $t->getSecteur()->getId(),
            'secteur' => $t->getSecteur()->getNom(), 'dateDebut' => $t->getDateDebut()->format('Y-m-d'), 'statut' => $t->getStatut()->value,
            'stockCalendriers' => $t->getStockCalendriers(), 'equipe' => array_map(fn ($a) => $this->userData($a->getUser()), $affectations),
            'adresses' => count($adresses), 'traitees' => $traitees, 'progression' => count($adresses) ? (int) round($traitees * 100 / count($adresses)) : 0,
            'adressesAVisiter' => count($adresses) - $traitees,
            'calendriersVendus' => $calendriers, 'calendriersDonnes' => $dons, 'calendriersDistribues' => $calendriers + $dons,
            'montant' => self::decimal($total), 'paiements' => array_map(self::decimal(...), $paiements),
            'carnets' => array_map(fn ($c) => $this->carnetData($c, $detail), $this->carnets($t))];
        if ($detail) {
            $byAdresse = [];
            foreach ($passages as $p) {
                $byAdresse[(string) $p->getAdresse()->getId()] = $this->passageData($p);
            }
            $result['listeAdresses'] = array_map(fn ($a) => [...$this->adresseData($a), 'passage' => $byAdresse[(string) $a->getId()] ?? null], $adresses);
        }
        return $result;
    }

    public function dashboard(int $year): array
    {
        $tournees = array_map(fn ($t) => $this->tourneeData($t), $this->tournees($year));
        $total = $vendus = $dons = $stock = $adresses = $traitees = $actives = 0;
        $paiements = array_fill_keys(array_column(\App\Enum\MoyenPaiement::cases(), 'value'), 0);
        foreach ($tournees as $t) {
            $total += self::cents($t['montant']);
            $vendus += $t['calendriersVendus'];
            $dons += $t['calendriersDonnes'];
            $stock += $t['stockCalendriers'];
            $adresses += $t['adresses'];
            $traitees += $t['traitees'];
            $actives += $t['statut'] === 'EN_COURS' ? 1 : 0;
            foreach ($paiements as $key => $_) {
                $paiements[$key] += self::cents($t['paiements'][$key]);
            }
        }
        return ['campagne' => $year, 'tournees' => $tournees, 'carnets' => array_merge([], ...array_column($tournees, 'carnets')), 'stats' => ['calendriersVendus' => $vendus, 'montant' => self::decimal($total),
            'calendriersDonnes' => $dons, 'calendriersDistribues' => $vendus + $dons, 'stockCalendriers' => $stock,
            'tourneesActives' => $actives, 'adresses' => $adresses, 'traitees' => $traitees, 'progression' => $adresses ? (int) round($traitees * 100 / $adresses) : 0,
            'adressesAVisiter' => $adresses - $traitees,
            'paiements' => array_map(self::decimal(...), $paiements)]];
    }
}
