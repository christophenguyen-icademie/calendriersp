<?php

namespace App\Geo;

use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/** Official address.data.gouv.fr geocoder, now hosted by the Géoplateforme. */
final class AddressGeocoder
{
    public function __construct(private JsonTransport $http, private string $geocoderUrl, private string $cacheDir) {}

    public function search(string $query): array
    {
        if (!is_dir($this->cacheDir) && !@mkdir($this->cacheDir, 0770, true) && !is_dir($this->cacheDir)) {
            throw new ServiceUnavailableHttpException(null, 'La recherche d’adresse est temporairement indisponible.');
        }
        // Include the provider URL so changing services cannot reuse old empty results.
        $file = $this->cacheDir.'/'.hash('sha256', $this->geocoderUrl.'|'.mb_strtolower(trim($query))).'.json';
        $lock = @fopen($this->cacheDir.'/requests.lock', 'c+');
        if (!$lock) { throw new ServiceUnavailableHttpException(null, 'Recherche d’adresse indisponible.'); }
        if (!flock($lock, LOCK_EX)) { fclose($lock); throw new ServiceUnavailableHttpException(null, 'Recherche d’adresse indisponible.'); }
        try {
            if (is_file($file) && filemtime($file) > time() - 86400) {
                $cached = json_decode((string) @file_get_contents($file), true);
                if (is_array($cached)) { return $cached; }
            }
            $last = (float) stream_get_contents($lock);
            $wait = 1.05 - (microtime(true) - $last);
            if ($wait > 0) { usleep((int) ($wait * 1000000)); }
            ftruncate($lock, 0); rewind($lock); fwrite($lock, (string) microtime(true)); fflush($lock);
            $url = $this->geocoderUrl.(str_contains($this->geocoderUrl, '?') ? '&' : '?').http_build_query(['q' => $query, 'limit' => 5]);
            $data = $this->http->request($url);
            if (($data['type'] ?? null) !== 'FeatureCollection' || !isset($data['features']) || !is_array($data['features'])) {
                throw new ServiceUnavailableHttpException(null, 'Le service officiel d’adresses a renvoyé une réponse invalide.');
            }
            $result = [];
            foreach ($data['features'] as $item) {
                $point = $item['geometry']['coordinates'] ?? null;
                $label = $item['properties']['label'] ?? null;
                if (($item['geometry']['type'] ?? null) !== 'Point' || !is_array($point) || count($point) !== 2
                    || !is_numeric($point[0] ?? null) || !is_numeric($point[1] ?? null)
                    || !is_finite((float) $point[0]) || !is_finite((float) $point[1]) || abs((float) $point[0]) > 180 || abs((float) $point[1]) > 90
                    || !is_string($label) || trim($label) === '' || mb_strlen($label) > 500) { continue; }
                $result[] = ['label' => trim($label), 'latitude' => (float) $point[1], 'longitude' => (float) $point[0]];
                if (count($result) === 5) { break; }
            }
            if ($data['features'] && !$result) {
                throw new ServiceUnavailableHttpException(null, 'Le service officiel d’adresses a renvoyé des lieux sans coordonnées exploitables.');
            }
            file_put_contents($file, json_encode($result, JSON_THROW_ON_ERROR));
            return $result;
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }
}
