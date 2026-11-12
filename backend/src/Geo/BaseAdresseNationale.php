<?php

namespace App\Geo;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

final class BaseAdresseNationale
{
    public function __construct(private CachedDownload $downloads, private Connection $db, private string $banUrl, private string $departmentsUrl) {}

    public function addresses(array $geometry): array
    {
        $path = $this->downloads->file($this->departmentsUrl);
        try { $boundaries = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { unlink($path); throw $this->invalidSource(); }
        if (($boundaries['type'] ?? null) !== 'FeatureCollection' || !is_array($boundaries['features'] ?? null) || !$boundaries['features']) {
            unlink($path); throw $this->invalidSource();
        }
        // The published outlines are simplified to 100 m. A 500 m margin also
        // loads neighbouring departments; exact address clipping happens later.
        $departments = $this->db->fetchFirstColumn('SELECT DISTINCT d->\'properties\'->>\'code\' FROM jsonb_array_elements(CAST(:features AS jsonb)) d WHERE ST_Intersects(ST_SetSRID(ST_GeomFromGeoJSON(d->\'geometry\'), 4326), ST_Buffer(ST_SetSRID(ST_GeomFromGeoJSON(:g), 4326)::geography, 500)::geometry)',
            ['features' => json_encode($boundaries['features'], JSON_THROW_ON_ERROR), 'g' => json_encode($geometry, JSON_THROW_ON_ERROR)]);
        if (!$departments) { throw new \InvalidArgumentException('La Base Adresse Nationale ne couvre pas cette zone. Choisissez la source OpenStreetMap.'); }
        if (count($departments) > 4) { throw new \InvalidArgumentException('Ce polygone couvre plus de quatre départements. Réduisez la zone pour extraire toutes ses adresses.'); }
        sort($departments);
        $ring = $geometry['coordinates'][0];
        $lons = array_column($ring, 0); $lats = array_column($ring, 1);
        $bounds = [min($lons), min($lats), max($lons), max($lats)];
        $result = [];
        $skipped = 0;
        foreach ($departments as $department) {
            if (!preg_match('/^(?:[0-9]{2,3}|2[AB])$/D', $department)) { throw $this->invalidSource(); }
            $csv = $this->downloads->file(rtrim($this->banUrl, '/').'/adresses-'.$department.'.csv.gz', true);
            $stream = fopen($csv, 'rb');
            if (!$stream) { throw $this->invalidSource(); }
            try {
                $header = fgetcsv($stream, 0, ';', '"', '');
                $required = ['id', 'numero', 'rep', 'nom_voie', 'code_postal', 'nom_commune', 'lon', 'lat'];
                if (!is_array($header) || array_diff($required, $header)) { unlink($csv); throw $this->invalidSource(); }
                while (($row = fgetcsv($stream, 0, ';', '"', '')) !== false) {
                    if ($row === [null]) { continue; }
                    if (count($row) !== count($header)) { unlink($csv); throw $this->invalidSource(); }
                    $a = array_combine($header, $row);
                    if (!is_numeric($a['lon']) || !is_numeric($a['lat'])) { ++$skipped; continue; }
                    $lon = (float) $a['lon']; $lat = (float) $a['lat'];
                    if ($lon < $bounds[0] || $lon > $bounds[2] || $lat < $bounds[1] || $lat > $bounds[3]) { continue; }
                    $line = implode(' ', array_filter(array_map('trim', [$a['numero'], $a['rep'], $a['nom_voie']]), fn ($v) => $v !== ''));
                    if (trim($a['id']) === '' || trim($a['numero']) === '' || trim($a['nom_voie']) === '' || mb_strlen($line) > 180 || strlen($a['id']) > 76 || mb_strlen($a['code_postal']) > 10 || mb_strlen($a['nom_commune']) > 120 || !is_finite($lon) || !is_finite($lat)) {
                        ++$skipped; continue;
                    }
                    $result[] = ['ligne1' => $line, 'codePostal' => trim($a['code_postal']), 'ville' => trim($a['nom_commune']), 'longitude' => $lon, 'latitude' => $lat, 'sourceId' => 'ban:'.$a['id']];
                    if (count($result) > 10000) { throw new \InvalidArgumentException('Plus de 10 000 adresses trouvées. Réduisez le polygone.'); }
                }
            } finally { fclose($stream); }
        }
        return ['addresses' => $result, 'skipped' => $skipped, 'departments' => $departments];
    }

    private function invalidSource(): ServiceUnavailableHttpException
    {
        return new ServiceUnavailableHttpException(null, 'Les données de la Base Adresse Nationale sont invalides ou incomplètes. Aucun import effectué.');
    }
}
