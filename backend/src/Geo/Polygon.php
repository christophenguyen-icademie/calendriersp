<?php

namespace App\Geo;

use Doctrine\DBAL\Connection;

/** GeoJSON uses [longitude, latitude]; PostGIS validates and clips all results. */
final class Polygon
{
    public function __construct(private Connection $db) {}

    public function validate(mixed $value): ?array
    {
        if ($value === null) { return null; }
        if (!is_array($value) || ($value['type'] ?? null) !== 'Polygon' || !isset($value['coordinates']) || !is_array($value['coordinates']) || count($value['coordinates']) !== 1) {
            throw new \InvalidArgumentException('Le secteur doit être un polygone GeoJSON simple, sans trou.');
        }
        $ring = $value['coordinates'][0];
        if (!is_array($ring) || !array_is_list($ring) || count($ring) < 4 || count($ring) > 501) {
            throw new \InvalidArgumentException('Le polygone doit contenir entre 3 et 500 sommets, puis le premier point répété.');
        }
        foreach ($ring as $p) {
            if (!is_array($p) || !array_is_list($p) || count($p) !== 2 || !is_numeric($p[0]) || !is_numeric($p[1]) || !is_finite((float) $p[0]) || !is_finite((float) $p[1]) || abs((float) $p[0]) > 180 || abs((float) $p[1]) > 85) {
                throw new \InvalidArgumentException('Coordonnées du polygone invalides.');
            }
        }
        $ring = array_map(fn ($p) => [(float) $p[0], (float) $p[1]], $ring);
        if ($ring[0] !== $ring[count($ring) - 1]) {
            throw new \InvalidArgumentException('Le polygone doit être fermé.');
        }
        $geometry = ['type' => 'Polygon', 'coordinates' => [$ring]];
        $json = json_encode($geometry, JSON_THROW_ON_ERROR);
        if (!$this->db->fetchOne('SELECT ST_IsValid(ST_SetSRID(ST_GeomFromGeoJSON(:g), 4326))', ['g' => $json])) {
            throw new \InvalidArgumentException('Le polygone est invalide : vérifiez que ses côtés ne se croisent pas.');
        }
        $area = (float) $this->db->fetchOne('SELECT ST_Area(ST_SetSRID(ST_GeomFromGeoJSON(:g), 4326)::geography)', ['g' => $json]);
        if ($area <= 0 || $area > 100000000) {
            throw new \InvalidArgumentException('La surface du secteur doit être positive et ne pas dépasser 100 km².');
        }
        return $geometry;
    }

    public function inside(array $geometry, array $addresses): array
    {
        if ($addresses === []) { return []; }
        $points = array_map(fn ($a, $i) => ['idx' => $i, 'lon' => $a['longitude'], 'lat' => $a['latitude']], $addresses, array_keys($addresses));
        $indices = $this->db->fetchFirstColumn('SELECT p.idx FROM jsonb_to_recordset(CAST(:points AS jsonb)) AS p(idx int, lon float8, lat float8) WHERE ST_Covers(ST_SetSRID(ST_GeomFromGeoJSON(:g), 4326), ST_SetSRID(ST_MakePoint(p.lon, p.lat), 4326))',
            ['points' => json_encode($points, JSON_THROW_ON_ERROR), 'g' => json_encode($geometry, JSON_THROW_ON_ERROR)]);
        return array_values(array_intersect_key($addresses, array_flip($indices)));
    }
}
