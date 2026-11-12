<?php

namespace App\Controller;

use App\Api\{Campaigns, Input, Workspace};
use App\Entity\Campagne;
use Doctrine\DBAL\LockMode;
use Symfony\Component\HttpFoundation\{JsonResponse, Request};
use Symfony\Component\Routing\Attribute\Route;

final class CampaignController
{
    public function __construct(private Workspace $w, private Campaigns $campaigns) {}

    #[Route('/api/campagnes', methods: ['GET', 'POST'])]
    public function campaigns(Request $request): JsonResponse
    {
        $this->w->requireManager();
        $amicale = $this->w->user()->getAmicale();
        if ($request->isMethod('GET')) {
            return new JsonResponse(array_map($this->campaigns->data(...), $this->w->em->getRepository(Campagne::class)->findBy(['amicale' => $amicale], ['annee' => 'DESC'])));
        }
        $year = Input::integer($request->toArray(), 'annee', 2000, 2100);
        $campaign = $this->w->em->wrapInTransaction(function () use ($amicale, $year): Campagne {
            $this->w->em->refresh($amicale, LockMode::PESSIMISTIC_WRITE);
            if ($this->w->em->getRepository(Campagne::class)->findOneBy(['amicale' => $amicale, 'annee' => $year])) { throw new \DomainException('Cette campagne existe déjà.'); }
            $campaign = new Campagne($amicale, $year);
            $this->w->em->persist($campaign);
            return $campaign;
        });
        return new JsonResponse($this->campaigns->data($campaign), 201);
    }
}
