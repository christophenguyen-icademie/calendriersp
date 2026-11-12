<?php

namespace App\Geo;

use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

final class OpenStreetMap
{
    public function __construct(private JsonTransport $http, private string $overpassUrl) {}

    public function addresses(array $geometry, string $postcode = '', string $city = ''): array
    {
        $points = array_slice($geometry['coordinates'][0], 0, -1);
        $poly = implode(' ', array_map(fn ($p) => sprintf('%.7F %.7F', $p[1], $p[0]), $points));
        $query = ['data' => '[out:json][timeout:25];nwr["addr:housenumber"](poly:"'.$poly.'");out body center;'];
        $url = $this->overpassUrl.(str_contains($this->overpassUrl, '?') ? '&' : '?').http_build_query($query);
        // Detailed boundaries use a POST body to avoid exceeding request URI limits.
        $data = strlen($url) <= 6000 ? $this->http->request($url) : $this->http->request($this->overpassUrl, $query);
        if (isset($data['remark']) || !isset($data['elements']) || !is_array($data['elements'])) {
            throw new ServiceUnavailableHttpException(null, 'L’extraction OpenStreetMap est incomplète. Aucun import effectué ; réessayez ou réduisez la zone.');
        }
        if (count($data['elements']) > 10000) { throw new \InvalidArgumentException('Plus de 10 000 adresses trouvées. Réduisez le polygone.'); }
        $result = [];
        $skipped = 0;
        foreach ($data['elements'] as $element) {
            $tags = $element['tags'] ?? [];
            $number = $tags['addr:housenumber'] ?? null;
            $street = $tags['addr:street'] ?? $tags['addr:place'] ?? null;
            $lat = $element['lat'] ?? $element['center']['lat'] ?? null;
            $lon = $element['lon'] ?? $element['center']['lon'] ?? null;
            $cp = $tags['addr:postcode'] ?? $postcode;
            $ville = $tags['addr:city'] ?? $city;
            if (!is_string($number) || !is_string($street) || trim($number) === '' || trim($street) === '' || !is_numeric($lat) || !is_numeric($lon) || !is_string($cp) || !is_string($ville)
                || !in_array($element['type'] ?? null, ['node', 'way', 'relation'], true) || !is_int($element['id'] ?? null)
                || mb_strlen(trim($number.' '.$street)) > 180 || mb_strlen($cp) > 10 || mb_strlen($ville) > 120 || abs((float) $lat) > 90 || abs((float) $lon) > 180) {
                ++$skipped; continue;
            }
            $result[] = ['ligne1' => trim($number.' '.$street), 'codePostal' => trim($cp), 'ville' => trim($ville), 'latitude' => (float) $lat, 'longitude' => (float) $lon,
                'sourceId' => 'osm:'.$element['type'].'/'.$element['id']];
        }
        return ['addresses' => $result, 'skipped' => $skipped];
    }
}
