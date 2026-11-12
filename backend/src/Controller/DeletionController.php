<?php

namespace App\Controller;

use App\Api\{Deletion, Workspace};
use App\Entity\{Tournee, TourneeAffectation, User};
use App\Enum\TourneeStatut;
use Doctrine\DBAL\LockMode;
use Symfony\Component\HttpFoundation\{JsonResponse, Request};
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class DeletionController
{
    public function __construct(private Workspace $w, private Deletion $deletion) {}

    #[Route('/api/{resource}/{id}/suppression', methods: ['GET'], requirements: ['resource' => 'sapeurs|secteurs|adresses|tournees|campagnes|carnets|passages'])]
    #[Route('/api/{resource}/{id}', methods: ['DELETE'], requirements: ['resource' => 'sapeurs|secteurs|adresses|tournees|campagnes|carnets|passages'])]
    public function delete(string $resource, string $id, Request $request): JsonResponse
    {
        $this->w->requireManager();
        if ($resource === 'sapeurs') { $this->w->requireAdmin(); }
        $entity = $this->w->owned(Deletion::RESOURCES[$resource], $id);
        if ($request->isMethod('GET')) { return new JsonResponse($this->deletion->inspect($entity)); }
        return $this->w->em->wrapInTransaction(function () use ($entity): JsonResponse {
            $this->deletion->lock($entity);
            $inspection = $this->deletion->inspect($entity);
            if (!$inspection['autorisee']) {
                return new JsonResponse(['error' => 'Suppression impossible : cet élément est utilisé ou protégé.', 'code' => 'DELETION_BLOCKED', ...$inspection], 409);
            }
            $this->deletion->remove($entity);
            return new JsonResponse(['supprime' => true]);
        });
    }

    #[Route('/api/tournees/{id}/affectations/{userId}/suppression', methods: ['GET'])]
    #[Route('/api/tournees/{id}/affectations/{userId}', methods: ['DELETE'])]
    public function unassign(string $id, string $userId, Request $request): JsonResponse
    {
        $this->w->requireManager();
        $tour = $this->w->owned(Tournee::class, $id);
        $user = $this->w->owned(User::class, $userId);
        $action = function () use ($tour, $user, $request): JsonResponse {
            $assignment = $this->w->em->getRepository(TourneeAffectation::class)->findOneBy(['tournee' => $tour, 'user' => $user]);
            if (!$assignment) { throw new NotFoundHttpException('Affectation introuvable.'); }
            $inspection = ['autorisee' => $tour->getStatut() !== TourneeStatut::VALIDEE, 'dependances' => [],
                'raisons' => $tour->getStatut() === TourneeStatut::VALIDEE ? ['La tournée est validée : ses affectations sont verrouillées.'] : [],
                'consequences' => ['Le sapeur ne fera plus partie de cette équipe. Son compte et les passages saisis sont conservés.']];
            if ($request->isMethod('GET')) { return new JsonResponse($inspection); }
            if (!$inspection['autorisee']) { return new JsonResponse(['error' => 'Suppression impossible : la tournée est validée.', 'code' => 'DELETION_BLOCKED', ...$inspection], 409); }
            $this->w->em->remove($assignment);
            return new JsonResponse(['supprime' => true]);
        };
        if ($request->isMethod('GET')) { return $action(); }
        return $this->w->em->wrapInTransaction(function () use ($tour, $action): JsonResponse {
            $this->w->em->refresh($tour, LockMode::PESSIMISTIC_WRITE);
            return $action();
        });
    }
}
