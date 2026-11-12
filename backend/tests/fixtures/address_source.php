<?php

// Deterministic upstream for compose.test.yml. Not used by the local application.
header('Content-Type: application/json');
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/departments') {
    $rectangle = static fn ($code, $west, $south, $east, $north) => [
        'type' => 'Feature', 'properties' => ['code' => $code],
        'geometry' => ['type' => 'Polygon', 'coordinates' => [[[$west,$south],[$east,$south],[$east,$north],[$west,$north],[$west,$south]]]],
    ];
    echo json_encode(['type' => 'FeatureCollection', 'features' => [
        $rectangle('75', 2.34, 48.85, 2.38, 48.87), $rectangle('77', 2.38, 48.85, 2.42, 48.87),
        $rectangle('43', 2.34, 43.0, 2.42, 43.1), $rectangle('44', 2.34, 44.0, 2.42, 44.1),
        $rectangle('45', 2.34, 45.0, 2.42, 45.1), $rectangle('46', 2.34, 46.0, 2.42, 46.1),
    ]]);
    return;
}
if (preg_match('~^/ban/adresses-(\d+)\.csv\.gz$~', $path, $match)) {
    header('Content-Type: application/gzip');
    $header = "id;numero;rep;nom_voie;code_postal;nom_commune;lon;lat\n";
    $csv = $header."75_1;1;;rue du Polygone;75004;Paris;2.3518;48.8567\n"
        ."75_2;2;bis;rue du Polygone;75004;Paris;2.3522;48.8569\n"
        ."75_3;1;;Rue du Polygone;75004;Paris;2.3518;48.8567\n"
        ."75_4;4;;rue du Polygone;75004;Paris;2.355;48.8567\n"
        ."75_6;6;;rue du Polygone;75004;Paris;2.352;48.8565\n"
        ."75_7;7;;rue du Polygone;75004;Paris;2.3523;48.8571\n"
        ."75_8;8;;rue Frontiere;75004;Paris;2.3798;48.8567\n";
    if ($match[1] === '77') { $csv = $header."77_9;9;;rue Frontiere;77000;Voisine;2.3802;48.8567\n"; }
    if ($match[1] === '46') { http_response_code(429); echo 'busy'; return; }
    if ($match[1] === '45') { $csv = "unexpected;schema\n1;2\n"; }
    $compressed = gzencode($csv);
    if ($match[1] === '44') { $compressed = substr($compressed, 0, -8); }
    if ($match[1] === '43') { $compressed[strlen($compressed) - 8] = chr(ord($compressed[strlen($compressed) - 8]) ^ 255); }
    echo $compressed;
    return;
}
if (str_starts_with($_SERVER['REQUEST_URI'], '/search')) {
    if (isset($_GET['format']) || ($_GET['limit'] ?? '') !== '5') { http_response_code(400); echo '{}'; return; }
    $query = strtolower(trim($_GET['q'] ?? ''));
    if ($query === 'service-indisponible') { http_response_code(429); echo '{}'; return; }
    if ($query === 'reponse-invalide') { echo json_encode([['lat' => '48.8568', 'lon' => '2.3520', 'display_name' => 'Ancien format']]); return; }
    $feature = static fn ($label, $coordinates, $type = 'street') => ['type' => 'Feature', 'geometry' => ['type' => 'Point', 'coordinates' => $coordinates], 'properties' => ['label' => $label, 'type' => $type]];
    $features = match (true) {
        $query === 'aucun-resultat' => [],
        $query === 'coordonnees-invalides' => [$feature('Point invalide', [4.05, 148.30])],
        str_contains($query, 'blanqui') => [$feature('Boulevard Blanqui 10000 Troyes', [4.052527, 48.3038]), $feature('10 Boulevard Blanqui 10000 Troyes', [4.0526, 48.3039], 'housenumber')],
        $query === 'troyes' => [$feature('Troyes', [4.0748, 48.2973], 'municipality')],
        default => [$feature('Paris, France (test)', [2.3520, 48.8568], 'municipality')],
    };
    echo json_encode(['type' => 'FeatureCollection', 'features' => $features]);
    return;
}
$query = $_POST['data'] ?? $_GET['data'] ?? '';
if (str_contains($query, '47.0000000')) {
    echo json_encode(['remark' => 'runtime error: timeout', 'elements' => []]);
    return;
}
if (str_contains($query, '46.0000000')) {
    http_response_code(429);
    echo json_encode(['error' => 'rate limited']);
    return;
}
$address = static fn ($number, $street = 'rue du Polygone') => ['addr:housenumber' => $number, 'addr:street' => $street, 'addr:postcode' => '75004', 'addr:city' => 'Paris'];
echo json_encode(['elements' => [
    ['type' => 'node', 'id' => 101, 'lat' => 48.8567, 'lon' => 2.3518, 'tags' => $address('1')],
    ['type' => 'way', 'id' => 102, 'center' => ['lat' => 48.8569, 'lon' => 2.3522], 'tags' => $address('2')],
    // Same physical address mapped both as a node and a building.
    ['type' => 'node', 'id' => 103, 'lat' => 48.8569, 'lon' => 2.3522, 'tags' => $address('2')],
    ['type' => 'node', 'id' => 104, 'lat' => 48.8568, 'lon' => 2.3519, 'tags' => ['addr:housenumber' => '3', 'addr:street' => 'rue du Polygone']],
    // Deliberately outside: clipping must be performed by the application.
    ['type' => 'node', 'id' => 105, 'lat' => 49.0, 'lon' => 3.0, 'tags' => $address('4')],
    ['type' => 'node', 'id' => 106, 'lat' => 48.8567, 'lon' => 2.3521, 'tags' => ['addr:housenumber' => '5']],
    // Point on the boundary must be included (ST_Covers).
    ['type' => 'relation', 'id' => 107, 'center' => ['lat' => 48.8565, 'lon' => 2.3520], 'tags' => $address('6')],
]]);
