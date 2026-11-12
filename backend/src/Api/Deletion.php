<?php

namespace App\Api;

use App\Entity\{Adresse, Campagne, Carnet, Passage, Recu, Secteur, Tournee, User};
use App\Enum\TourneeStatut;
use Doctrine\DBAL\LockMode;

final class Deletion
{
    public const RESOURCES = ['sapeurs' => User::class, 'secteurs' => Secteur::class, 'adresses' => Adresse::class,
        'tournees' => Tournee::class, 'campagnes' => Campagne::class, 'carnets' => Carnet::class, 'passages' => Passage::class];

    public function __construct(private Workspace $w) {}

    public function lock(object $entity): void
    {
        // Same parent locks/order as creation, imports, passages and settings.
        if ($entity instanceof Campagne) { $this->w->em->refresh($entity->getAmicale(), LockMode::PESSIMISTIC_WRITE); }
        if ($entity instanceof Adresse) { $this->w->em->refresh($entity->getSecteur(), LockMode::PESSIMISTIC_WRITE); }
        if ($entity instanceof Carnet || $entity instanceof Passage) { $this->w->em->refresh($entity->getTournee(), LockMode::PESSIMISTIC_WRITE); }
        $this->w->em->refresh($entity, LockMode::PESSIMISTIC_WRITE);
    }

    public function inspect(object $entity): array
    {
        $id = (string) $entity->getId();
        $dependencies = $reasons = $effects = [];
        $count = function (string $type, string $label, string $sql, array $params = []) use (&$dependencies, $id): void {
            $number = (int) $this->w->em->getConnection()->fetchOne($sql, $params ?: ['id' => $id]);
            if ($number > 0) { $dependencies[] = ['type' => $type, 'nombre' => $number, 'libelle' => $label]; }
        };
        if ($entity instanceof User) {
            $count('affectations', 'affectations à des tournées', 'SELECT COUNT(*) FROM tournee_affectation WHERE user_id = :id');
            $count('passages', 'passages saisis', 'SELECT COUNT(*) FROM passage WHERE saisi_par_id = :id');
            if ($entity->getId()->equals($this->w->user()->getId())) { $reasons[] = 'Vous ne pouvez pas supprimer votre propre compte connecté.'; }
        } elseif ($entity instanceof Secteur) {
            $count('adresses', 'adresses du secteur', 'SELECT COUNT(*) FROM adresse WHERE secteur_id = :id');
            $count('tournees', 'tournées du secteur', 'SELECT COUNT(*) FROM tournee WHERE secteur_id = :id');
        } elseif ($entity instanceof Adresse) {
            $count('passages', 'passages à cette adresse', 'SELECT COUNT(*) FROM passage WHERE adresse_id = :id');
            // Tours use the current address list of their sector, even before a passage exists.
            $count('tournees', 'tournées utilisant le secteur de cette adresse', 'SELECT COUNT(*) FROM tournee WHERE secteur_id = :id', ['id' => (string) $entity->getSecteur()->getId()]);
        } elseif ($entity instanceof Tournee) {
            $count('affectations', 'affectations de sapeurs', 'SELECT COUNT(*) FROM tournee_affectation WHERE tournee_id = :id');
            $count('carnets', 'carnets rattachés', 'SELECT COUNT(*) FROM carnet WHERE tournee_id = :id');
            $count('passages', 'passages enregistrés', 'SELECT COUNT(*) FROM passage WHERE tournee_id = :id');
        } elseif ($entity instanceof Campagne) {
            $count('tournees', 'tournées de cette campagne', 'SELECT COUNT(*) FROM tournee WHERE campagne_id = :id');
            if ($entity->getAnnee() === ($entity->getAmicale()->getParametres()['campagne'] ?? (int) date('Y'))) {
                $reasons[] = 'Cette campagne est définie par défaut dans les paramètres de l’amicale. Choisissez une autre campagne par défaut avant de la supprimer.';
            }
        } elseif ($entity instanceof Carnet) {
            $count('recusUtilises', 'reçus déjà utilisés, y compris les annulés', 'SELECT COUNT(*) FROM recu WHERE carnet_id = :id AND passage_id IS NOT NULL');
            $count('passages', 'passages utilisant un reçu de ce carnet', 'SELECT COUNT(*) FROM passage p JOIN recu r ON p.recu_id = r.id WHERE r.carnet_id = :id');
            $effects[] = 'Les '.$entity->getNombreRecus().' reçus inutilisés du carnet seront supprimés avec lui.';
        } elseif ($entity instanceof Passage) {
            $count('recusUtilises', 'reçus consommés par ce passage, y compris les annulés', 'SELECT COUNT(*) FROM recu WHERE passage_id = :id');
            if ($entity->getRecu()) { $reasons[] = 'Ce passage référence un reçu. Son historique doit être conservé.'; }
        }
        $tour = $entity instanceof Tournee ? $entity : (($entity instanceof Carnet || $entity instanceof Passage) ? $entity->getTournee() : null);
        if ($tour?->getStatut() === TourneeStatut::VALIDEE) { $reasons[] = 'La tournée est validée : ses données sont verrouillées.'; }
        return ['autorisee' => !$dependencies && !$reasons, 'dependances' => $dependencies, 'raisons' => $reasons, 'consequences' => $effects];
    }

    public function remove(object $entity): void
    {
        if ($entity instanceof Carnet) {
            foreach ($this->w->em->getRepository(Recu::class)->findBy(['carnet' => $entity]) as $receipt) { $this->w->em->remove($receipt); }
        }
        $this->w->em->remove($entity);
    }
}
