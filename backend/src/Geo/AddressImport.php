<?php

namespace App\Geo;

use App\Entity\{Adresse, Secteur};
use Doctrine\ORM\EntityManagerInterface;

final class AddressImport
{
    public function __construct(private EntityManagerInterface $em) {}

    public function plan(Secteur $sector, array $addresses): array
    {
        $sources = $identities = [];
        $existing = $this->em->getConnection()->fetchAllAssociative('SELECT ligne1, code_postal, ville, source_id FROM adresse WHERE secteur_id = :id', ['id' => (string) $sector->getId()]);
        foreach ($existing as $a) {
            if ($a['source_id']) { $sources[$a['source_id']] = true; }
            $identities[Adresse::identity($a['ligne1'], $a['code_postal'], $a['ville'])] = true;
        }
        $result = [];
        foreach ($addresses as $address) {
            $identity = Adresse::identity($address['ligne1'], $address['codePostal'], $address['ville']);
            $duplicate = isset($sources[$address['sourceId']]) || isset($identities[$identity]);
            $result[] = [...$address, 'doublon' => $duplicate];
            $sources[$address['sourceId']] = true;
            $identities[$identity] = true;
        }
        return $result;
    }

    /** Caller holds the sector's database lock, shared with manual address creation. */
    public function apply(Secteur $sector, array $addresses): array
    {
        $created = $duplicates = 0;
        foreach ($this->plan($sector, $addresses) as $a) {
            if ($a['doublon']) { ++$duplicates; continue; }
            $entity = new Adresse($sector, $a['ligne1'], $a['codePostal'], $a['ville']);
            $entity->setSourceId($a['sourceId'])->setPosition($a['latitude'], $a['longitude']);
            $this->em->persist($entity);
            ++$created;
        }
        return ['creees' => $created, 'doublons' => $duplicates];
    }
}
