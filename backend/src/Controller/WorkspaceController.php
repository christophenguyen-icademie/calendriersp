<?php

namespace App\Controller;

use App\Api\{Campaigns, Input, Workspace};
use App\Entity\{Adresse, Amicale, Carnet, Passage, Recu, Secteur, Tournee, TourneeAffectation, User};
use App\Enum\{MoyenPaiement, PassageStatut, TourneeStatut};
use Doctrine\DBAL\LockMode;
use App\Geo\Polygon;
use Symfony\Component\HttpFoundation\{JsonResponse, Request};
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

final class WorkspaceController
{
    public function __construct(private Workspace $w, private Campaigns $campaigns) {}

    #[Route('/api/dashboard', methods: ['GET'])]
    public function dashboard(Request $request): JsonResponse
    {
        $this->w->requireManager();
        $default = $this->w->profile()['amicale']['campagne'];
        $raw = $request->query->get('campagne', (string) $default);
        if (!is_string($raw) || !preg_match('/^\d{4}$/D', $raw)) {
            throw new \InvalidArgumentException('Campagne invalide.');
        }
        $year = Input::integer(['campagne' => (int) $raw], 'campagne', 2000, 2100);
        return new JsonResponse($this->w->dashboard($year));
    }

    #[Route('/api/secteurs', methods: ['GET', 'POST'])]
    public function secteurs(Request $request, Polygon $polygon): JsonResponse
    {
        $this->w->requireManager();
        if ($request->isMethod('GET')) {
            return new JsonResponse(array_map($this->w->secteurData(...), $this->w->secteurs()));
        }
        $d = $request->toArray();
        $s = new Secteur($this->w->user()->getAmicale(), Input::text($d, 'nom', 120), Input::choice($d + ['type' => 'LIBRE'], 'type', ['LIBRE', 'URBAIN', 'RURAL']));
        $s->setPolygone($polygon->validate($d['polygone'] ?? null));
        $this->w->em->persist($s);
        $this->w->em->flush();
        return new JsonResponse($this->w->secteurData($s), 201);
    }

    #[Route('/api/adresses', methods: ['GET', 'POST'])]
    public function adresses(Request $request): JsonResponse
    {
        $this->w->requireManager();
        if ($request->isMethod('GET')) {
            return new JsonResponse(array_map($this->w->adresseData(...), $this->w->adresses()));
        }
        $d = $request->toArray();
        $secteur = $this->w->owned(Secteur::class, Input::id($d, 'secteurId'));
        $a = new Adresse($secteur, Input::text($d, 'ligne1'), Input::text($d, 'codePostal', 10), Input::text($d, 'ville', 120));
        $a->setLogement($d);
        $this->w->em->wrapInTransaction(function () use ($secteur, $a): void {
            $this->w->em->refresh($secteur, LockMode::PESSIMISTIC_WRITE);
            $this->w->em->persist($a);
        });
        return new JsonResponse($this->w->adresseData($a), 201);
    }

    #[Route('/api/sapeurs', methods: ['GET', 'POST'])]
    public function sapeurs(Request $request, UserPasswordHasherInterface $hasher): JsonResponse
    {
        $this->w->requireManager();
        if ($request->isMethod('GET')) {
            return new JsonResponse(array_map($this->w->userData(...), $this->w->users()));
        }
        $this->w->requireAdmin();
        $d = $request->toArray();
        $email = Input::text($d, 'email');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Adresse e-mail invalide.');
        }
        $role = Input::choice($d, 'role', ['ROLE_SAPEUR', 'ROLE_RESPONSABLE', 'ROLE_ADMIN_AMICALE']);
        $u = new User($this->w->user()->getAmicale(), $email);
        // Les anciens clients peuvent encore omettre le nom ; le formulaire web le demande.
        if (array_key_exists('nom', $d)) { $u->setNom(Input::text($d, 'nom')); }
        $u->setRoles([$role])->setPassword($hasher->hashPassword($u, Input::password($d)));
        $this->w->em->persist($u);
        $this->w->em->flush();
        return new JsonResponse($this->w->userData($u), 201);
    }

    #[Route('/api/sapeurs/{id}', methods: ['PATCH'])]
    public function nomSapeur(string $id, Request $request): JsonResponse
    {
        $this->w->requireAdmin();
        $u = $this->w->owned(User::class, $id);
        $u->setNom(Input::text($request->toArray(), 'nom'));
        $this->w->em->flush();
        return new JsonResponse($this->w->userData($u));
    }

    #[Route('/api/tournees', methods: ['GET', 'POST'])]
    #[Route('/api/mobile/tournees', methods: ['GET'])]
    public function tournees(Request $request): JsonResponse
    {
        if ($request->isMethod('GET')) {
            return new JsonResponse(array_map(fn ($t) => $this->w->tourneeData($t), $this->w->tournees()));
        }
        $this->w->requireManager();
        $d = $request->toArray();
        $secteur = $this->w->owned(Secteur::class, Input::id($d, 'secteurId'));
        $nom = Input::text($d, 'nom', 140);
        $date = Input::date($d, 'dateDebut');
        $stock = Input::integer($d + ['stockCalendriers' => 0], 'stockCalendriers');
        $t = $this->w->em->wrapInTransaction(function () use ($secteur, $nom, $date, $stock): Tournee {
            $amicale = $this->w->user()->getAmicale();
            $this->w->em->refresh($amicale, LockMode::PESSIMISTIC_WRITE);
            $this->w->em->refresh($secteur, LockMode::PESSIMISTIC_WRITE);
            $campaign = $this->campaigns->ensure($amicale, (int) $date->format('Y'));
            $tour = new Tournee($amicale, $secteur, $nom, $date, $campaign);
            $tour->setStockCalendriers($stock);
            $this->w->em->persist($tour);
            return $tour;
        });
        return new JsonResponse($this->w->tourneeData($t), 201);
    }

    #[Route('/api/tournees/{id}', methods: ['GET'])]
    #[Route('/api/mobile/tournees/{id}/adresses', methods: ['GET'])]
    public function tournee(string $id): JsonResponse
    {
        return new JsonResponse($this->w->tourneeData($this->w->owned(Tournee::class, $id), true));
    }

    #[Route('/api/tournees/{id}/carnets', methods: ['GET', 'POST'])]
    public function carnets(string $id, Request $request): JsonResponse
    {
        $tournee = $this->w->owned(Tournee::class, $id);
        if ($request->isMethod('GET')) {
            return new JsonResponse(array_map(fn ($c) => $this->w->carnetData($c, true), $this->w->carnets($tournee)));
        }
        $this->w->requireManager();
        $d = $request->toArray();
        $carnet = new Carnet($tournee, Input::text($d, 'numero', 40), Input::integer($d, 'premierRecu', 1, 999999999),
            Input::integer($d + ['nombreRecus' => 25], 'nombreRecus', 1, 1000));
        $this->w->em->wrapInTransaction(function () use ($tournee, $carnet): void {
            $this->w->em->refresh($tournee, LockMode::PESSIMISTIC_WRITE);
            $this->assertWritable($tournee);
            $this->w->em->persist($carnet);
            for ($n = $carnet->getPremierRecu(); $n < $carnet->getPremierRecu() + $carnet->getNombreRecus(); ++$n) {
                $this->w->em->persist(new Recu($carnet, $n));
            }
        });
        return new JsonResponse($this->w->carnetData($carnet, true), 201);
    }

    #[Route('/api/tournees/{id}/affectations', methods: ['POST'])]
    public function affecter(string $id, Request $request): JsonResponse
    {
        $this->w->requireManager();
        $t = $this->w->owned(Tournee::class, $id);
        $d = $request->toArray();
        if (array_key_exists('userIds', $d) && array_key_exists('userId', $d)) {
            throw new \InvalidArgumentException('Renseignez userIds ou userId, pas les deux.');
        }
        $ids = array_key_exists('userIds', $d) ? $d['userIds'] : [Input::id($d, 'userId')];
        if (!is_array($ids) || !array_is_list($ids) || $ids === []) {
            throw new \InvalidArgumentException('Sélectionnez au moins un sapeur dans userIds.');
        }
        $users = [];
        foreach ($ids as $userId) {
            $user = $this->w->owned(User::class, Input::id(['userId' => $userId], 'userId'));
            if (!$user->isActive()) {
                throw new \DomainException('Ce compte est désactivé.');
            }
            $users[(string) $user->getId()] = $user;
        }
        $this->w->em->wrapInTransaction(function () use ($t, $users): void {
            $this->w->em->refresh($t, LockMode::PESSIMISTIC_WRITE);
            $this->assertWritable($t);
            foreach ($users as $user) {
                if (!$this->w->em->getRepository(TourneeAffectation::class)->findOneBy(['tournee' => $t, 'user' => $user])) {
                    $this->w->em->persist(new TourneeAffectation($t, $user));
                }
            }
        });
        return new JsonResponse($this->w->tourneeData($t));
    }

    #[Route('/api/tournees/{id}/statut', methods: ['PATCH'])]
    #[Route('/api/tournees/{id}/valider', methods: ['POST'])]
    public function statut(string $id, Request $request): JsonResponse
    {
        $this->w->requireManager();
        $t = $this->w->owned(Tournee::class, $id);
        $value = $request->isMethod('POST') ? 'VALIDEE' : Input::choice($request->toArray(), 'statut', ['PREPAREE', 'EN_COURS', 'TERMINEE', 'VALIDEE']);
        $this->w->em->wrapInTransaction(function () use ($t, $value): void {
            // Share the address-edit lock so a concurrent validation cannot freeze changing details.
            $this->w->em->refresh($t->getSecteur(), LockMode::PESSIMISTIC_WRITE);
            $this->w->em->refresh($t, LockMode::PESSIMISTIC_WRITE);
            $this->assertWritable($t);
            if ($value === 'VALIDEE' && $t->getStatut() !== TourneeStatut::TERMINEE) {
                throw new \DomainException('Terminez la tournée avant de la valider.');
            }
            $t->setStatut(TourneeStatut::from($value));
        });
        return new JsonResponse($this->w->tourneeData($t));
    }

    #[Route('/api/tournees/{id}/passages/{adresseId}', methods: ['PUT'])]
    public function passage(string $id, string $adresseId, Request $request): JsonResponse
    {
        return $this->savePassage($id, $adresseId, $request, 'existing');
    }

    #[Route('/api/tournees/{id}/adresses/{adresseId}/dupliquer', methods: ['POST'])]
    public function dupliquerAdresse(string $id, string $adresseId, Request $request): JsonResponse
    {
        return $this->savePassage($id, $adresseId, $request, 'duplicate');
    }

    #[Route('/api/tournees/{id}/adresses', methods: ['POST'])]
    public function nouvelleAdresse(string $id, Request $request): JsonResponse
    {
        return $this->savePassage($id, null, $request, 'new');
    }

    private function savePassage(string $id, ?string $adresseId, Request $request, string $mode): JsonResponse
    {
        $t = $this->w->owned(Tournee::class, $id);
        $d = $request->toArray();
        $newAddress = $mode === 'new';
        $duplicate = $mode === 'duplicate';
        $datePassage = array_key_exists('datePassage', $d) ? Input::date($d, 'datePassage') : null;
        if ($datePassage !== null && ($datePassage->format('Y-m-d') < '1900-01-01'
            || $datePassage->format('Y-m-d') > (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris')))->format('Y-m-d'))) {
            throw new \InvalidArgumentException('La date du passage doit être comprise entre le 01/01/1900 et aujourd’hui.');
        }
        $a = $newAddress
            ? new Adresse($t->getSecteur(), Input::text($d, 'ligne1'), Input::text($d, 'codePostal', 10), Input::text($d, 'ville', 120))
            : $this->w->owned(Adresse::class, $adresseId);
        if (!$a->getSecteur()->getId()->equals($t->getSecteur()->getId())) {
            throw new NotFoundHttpException('Adresse introuvable dans cette tournée.');
        }
        $statut = PassageStatut::from(Input::choice($d, 'statut', array_column(PassageStatut::cases(), 'value')));
        if ($statut === PassageStatut::DON) {
            $d += ['nbCalendriers' => 0, 'montant' => '0.00'];
        }
        $nb = Input::integer($d, 'nbCalendriers');
        $montant = Input::text($d, 'montant', 10);
        $paiement = ($d['moyenPaiement'] ?? null) === null ? null : MoyenPaiement::from(Input::choice($d, 'moyenPaiement', array_column(MoyenPaiement::cases(), 'value')));
        $commentaire = ($d['commentaire'] ?? null) === null || $d['commentaire'] === '' ? null : Input::text($d, 'commentaire', 2000);
        $recu = null;
        if (in_array($statut, [PassageStatut::VENTE, PassageStatut::DON], true)) {
            $carnet = $this->w->owned(Carnet::class, Input::id($d, 'carnetId'));
            $recu = $this->w->owned(Recu::class, Input::id($d, 'recuId'));
            if (!$carnet->getTournee()->getId()->equals($t->getId()) || !$recu->getCarnet()->getId()->equals($carnet->getId())) {
                throw new NotFoundHttpException('Reçu introuvable dans les carnets de cette tournée.');
            }
        } elseif (($d['carnetId'] ?? null) !== null || ($d['recuId'] ?? null) !== null) {
            throw new \InvalidArgumentException('Seuls une vente ou un don peuvent utiliser un reçu.');
        }
        $p = $this->w->em->wrapInTransaction(function () use ($t, $a, $d, $newAddress, $duplicate, $statut, $nb, $montant, $paiement, $commentaire, $recu, $datePassage): Passage {
            // Serialize address edits/duplication with imports, address deletion and other tours.
            $this->w->em->refresh($a->getSecteur(), LockMode::PESSIMISTIC_WRITE);
            $this->w->em->refresh($t, LockMode::PESSIMISTIC_WRITE);
            $this->assertWritable($t);
            if ($newAddress) {
                $a->setLogement($d);
                $this->w->em->persist($a);
            } else {
                $this->w->em->refresh($a, LockMode::PESSIMISTIC_WRITE);
            }
            $logement = array_replace($a->getLogement(), array_intersect_key($d, $a->getLogement()));
            if ($duplicate) {
                $a = $a->dupliquer($logement);
                $this->w->em->persist($a);
            } elseif (!$newAddress) {
                $before = $a->getLogement();
                $a->setLogement($logement);
                if ($before !== $a->getLogement() && $this->w->em->getConnection()->fetchOne(
                    "SELECT COUNT(*) FROM passage p JOIN tournee t ON t.id = p.tournee_id WHERE p.adresse_id = :id AND t.statut = 'VALIDEE'",
                    ['id' => (string) $a->getId()]
                )) {
                    throw new \DomainException('Cette adresse possède un passage validé. Ajoutez un autre appartement pour conserver son historique.');
                }
            }
            if ($recu !== null) {
                $this->w->em->refresh($recu, LockMode::PESSIMISTIC_WRITE);
            }
            $p = $this->w->em->getRepository(Passage::class)->findOneBy(['tournee' => $t, 'adresse' => $a]) ?? new Passage($t, $a, $this->w->user());
            $p->enregistrer($statut, $nb, $montant, $paiement, $commentaire, $recu, $datePassage);
            $this->w->em->persist($p);
            if ($t->getStatut() === TourneeStatut::PREPAREE) {
                $t->setStatut(TourneeStatut::EN_COURS);
            }
            return $p;
        });
        return $duplicate || $newAddress
            ? new JsonResponse([...$this->w->adresseData($p->getAdresse()), 'passage' => $this->w->passageData($p)], 201)
            : new JsonResponse($this->w->passageData($p));
    }

    #[Route('/api/parametres', methods: ['PATCH'])]
    public function parametres(Request $request): JsonResponse
    {
        $this->w->requireAdmin();
        $d = $request->toArray();
        $nom = Input::text($d, 'nom');
        $departement = Input::text($d, 'departement', 3);
        if (!preg_match('/^(?:\d{2,3}|2[AB])$/D', $departement)) {
            throw new \InvalidArgumentException('Département invalide.');
        }
        $year = Input::integer($d, 'campagne', 2000, 2100);
        $identifiant = array_key_exists('identifiant', $d) ? Amicale::normaliserIdentifiant($d['identifiant']) : null;
        $amicale = $this->w->user()->getAmicale();
        $this->w->em->wrapInTransaction(function () use ($amicale, $nom, $departement, $year, $identifiant): void {
            $this->w->em->refresh($amicale, LockMode::PESSIMISTIC_WRITE);
            if ($identifiant !== null) { $amicale->setIdentifiant($identifiant); }
            $this->campaigns->ensure($amicale, $year);
            $amicale->setNom($nom)->setDepartement($departement)->setParametres([...$amicale->getParametres(), 'campagne' => $year]);
        });
        return new JsonResponse($this->w->profile());
    }

    private function assertWritable(Tournee $t): void
    {
        if ($t->getStatut() === TourneeStatut::VALIDEE) {
            throw new \DomainException('La tournée est validée et ne peut plus être modifiée.');
        }
    }
}
