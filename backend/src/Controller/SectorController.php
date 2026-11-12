<?php

namespace App\Controller;

use App\Api\{Input, Workspace};
use App\Entity\Secteur;
use App\Geo\{AddressGeocoder, AddressImport, BaseAdresseNationale, OpenStreetMap, Polygon};
use Doctrine\DBAL\LockMode;
use Symfony\Component\HttpFoundation\{JsonResponse, Request};
use Symfony\Component\Routing\Attribute\Route;

final class SectorController
{
    public function __construct(private Workspace $w, private Polygon $polygon, private OpenStreetMap $osm, private AddressImport $importer, private BaseAdresseNationale $ban, private AddressGeocoder $geocoder) {}

    #[Route('/api/secteurs/{id}', methods: ['GET', 'PATCH'])]
    public function sector(string $id, Request $request): JsonResponse
    {
        $this->w->requireManager();
        $sector = $this->w->owned(Secteur::class, $id);
        if ($request->isMethod('PATCH')) {
            $d = $request->toArray();
            $geometry = array_key_exists('polygone', $d) ? $this->polygon->validate($d['polygone']) : null;
            $nom = array_key_exists('nom', $d) ? Input::text($d, 'nom', 120) : null;
            $type = array_key_exists('type', $d) ? Input::choice($d, 'type', ['LIBRE', 'URBAIN', 'RURAL']) : null;
            $this->w->em->wrapInTransaction(function () use ($sector, $geometry, $nom, $type, $d): void {
                $this->w->em->refresh($sector, LockMode::PESSIMISTIC_WRITE);
                if ($nom !== null) { $sector->setNom($nom); }
                if ($type !== null) { $sector->setType($type); }
                if (array_key_exists('polygone', $d)) { $sector->setPolygone($geometry); }
            });
        }
        return new JsonResponse($this->w->secteurData($sector));
    }

    #[Route('/api/carte/recherche', methods: ['GET'])]
    public function search(Request $request): JsonResponse
    {
        $this->w->requireManager();
        $query = Input::text(['q' => $request->query->get('q')], 'q');
        if (mb_strlen($query) < 3) { throw new \InvalidArgumentException('Saisissez au moins 3 caractères pour rechercher un lieu.'); }
        return new JsonResponse($this->geocoder->search($query));
    }

    #[Route('/api/secteurs/{id}/adresses/preview', methods: ['POST'])]
    public function preview(string $id, Request $request): JsonResponse
    {
        $this->w->requireManager();
        $sector = $this->w->owned(Secteur::class, $id);
        $geometry = $sector->getPolygone();
        if (!$geometry) { throw new \DomainException('Enregistrez un polygone pour ce secteur avant de rechercher ses adresses.'); }
        $d = $request->toArray();
        $postcode = ($d['codePostal'] ?? '') === '' ? '' : Input::text($d, 'codePostal', 10);
        $city = ($d['ville'] ?? '') === '' ? '' : Input::text($d, 'ville', 120);
        $source = Input::choice($d + ['source' => 'BAN'], 'source', ['BAN', 'OSM']);
        $extracted = $source === 'BAN' ? $this->ban->addresses($geometry) : $this->osm->addresses($geometry, $postcode, $city);
        $inside = $this->polygon->inside($geometry, $extracted['addresses']);
        $addresses = $this->importer->plan($sector, $inside);
        $token = bin2hex(random_bytes(24));
        $snapshots = $request->getSession()->get('sector_imports', []);
        $snapshots = array_filter($snapshots, fn ($s) => $s['created'] > time() - 900);
        $snapshots[$token] = ['sector' => $id, 'tenant' => (string) $this->w->user()->getAmicale()->getId(), 'hash' => $this->hash($geometry), 'created' => time(), 'addresses' => $inside];
        $request->getSession()->set('sector_imports', array_slice($snapshots, -4, null, true));
        $duplicates = count(array_filter($addresses, fn ($a) => $a['doublon']));
        return new JsonResponse(['token' => $token, 'source' => $source === 'BAN' ? 'Base Adresse Nationale (BAN)' : 'OpenStreetMap', 'departements' => $extracted['departments'] ?? [], 'adresses' => $addresses, 'total' => count($addresses), 'nouvelles' => count($addresses) - $duplicates,
            'doublons' => $duplicates, 'incompletes' => count(array_filter($addresses, fn ($a) => $a['codePostal'] === '' || $a['ville'] === '')),
            'horsPolygone' => count($extracted['addresses']) - count($inside), 'ignorees' => $extracted['skipped']]);
    }

    #[Route('/api/secteurs/{id}/adresses/import', methods: ['POST'])]
    public function import(string $id, Request $request): JsonResponse
    {
        $this->w->requireManager();
        $sector = $this->w->owned(Secteur::class, $id);
        $token = Input::text($request->toArray(), 'token', 48);
        $snapshots = $request->getSession()->get('sector_imports', []);
        $snapshot = $snapshots[$token] ?? null;
        if (!$snapshot || $snapshot['sector'] !== $id || $snapshot['tenant'] !== (string) $this->w->user()->getAmicale()->getId() || $snapshot['created'] < time() - 900) {
            throw new \DomainException('L’aperçu a expiré. Recherchez à nouveau les adresses.');
        }
        $result = $this->w->em->wrapInTransaction(function () use ($sector, $snapshot): array {
            $this->w->em->refresh($sector, LockMode::PESSIMISTIC_WRITE);
            if ($snapshot['hash'] !== $this->hash($sector->getPolygone())) {
                throw new \DomainException('Le polygone a changé depuis l’aperçu. Recherchez à nouveau les adresses.');
            }
            return $this->importer->apply($sector, $snapshot['addresses']);
        });
        unset($snapshots[$token]);
        $request->getSession()->set('sector_imports', $snapshots);
        return new JsonResponse($result);
    }

    private function hash(?array $geometry): string { return hash('sha256', json_encode($geometry, JSON_THROW_ON_ERROR)); }
}
