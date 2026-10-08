<?php
/**
 * Proxy generique vers l'api/ REST.
 *
 * Le navigateur appelle proxy.php?endpoint=<technologie>/<action>.php ; ce
 * script relaie la requete en cURL cote serveur vers API_BASE_URL en
 * ajoutant le header X-API-Key, qui ne doit jamais etre expose au navigateur.
 *
 * Usage : GET/POST proxy.php?endpoint=mobile/create_offre.php
 */

require_once __DIR__ . '/includes/session.php';
appSessionStart();
header('Content-Type: application/json');

if (!isset($_SESSION[APP_SESSION_LOGIN_KEY])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Session expiree, veuillez vous reconnecter.']);
    exit;
}

require_once __DIR__ . '/config/app.php';

// Endpoints ouverts pour les fronts construits (front 4G/Recharges a venir).
$allowedEndpoints = [
    'mobile/create_offre.php',
    'mobile/create_rateplan.php',
    'mobile/create_formule.php',
    'mobile/create_billing.php',
    'mobile/check_formule.php',
    'mobile/get_offre.php',
    'ftth/create_offre.php',
    'ftth/check_rateplan.php',
    'ftth/create_rateplan.php',
    'ftth/create_mpulktmb.php',
    'ftth/create_pricing.php',
    'ftth/get_offer.php',
];

$endpoint = isset($_GET['endpoint']) ? $_GET['endpoint'] : '';

if (!in_array($endpoint, $allowedEndpoints, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Endpoint inconnu ou non autorise : ' . $endpoint]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$url = rtrim(API_BASE_URL, '/') . '/' . $endpoint;

if ($method === 'GET') {
    $query = $_GET;
    unset($query['endpoint']);
    if (!empty($query)) {
        $url .= '?' . http_build_query($query);
    }
}

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'X-API-Key: ' . API_KEY,
    'Content-Type: application/json',
]);

$requestBody = '';
if ($method === 'POST') {
    $requestBody = file_get_contents('php://input');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $requestBody);
} elseif ($method !== 'GET') {
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
}

$response  = curl_exec($ch);
$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

$user = $_SESSION[APP_SESSION_LOGIN_KEY];
$now  = date('Y-m-d H:i:s');

// Resume court, une ligne par appel (lecture rapide).
$logLine = $now . ' | ' . $user . ' | ' . APP_MODE . ' | ' . $method . ' ' . $endpoint . ' | HTTP ' . $httpCode . "\n";
@file_put_contents(__DIR__ . '/logs/proxy.log', $logLine, FILE_APPEND);

// Detail complet (requete + reponse) dans un fichier separe, pour le debug.
// N'inclut jamais X-API-Key (secret).
$detail = str_repeat('=', 80) . "\n"
    . $now . ' | ' . $user . ' | ' . APP_MODE . "\n"
    . '--> ' . $method . ' ' . $url . "\n";
if ($requestBody !== '') {
    $detail .= 'Corps envoye : ' . $requestBody . "\n";
}
$detail .= '<-- HTTP ' . ($httpCode ?: 'N/A');
if ($curlError !== '') {
    $detail .= ' (erreur cURL : ' . $curlError . ')';
}
$detail .= "\n" . 'Reponse : ' . ($response !== false ? substr($response, 0, 5000) : '(aucune reponse)') . "\n\n";
@file_put_contents(__DIR__ . '/logs/proxy_detail.log', $detail, FILE_APPEND);

if ($response === false) {
    http_response_code(502);
    echo json_encode(['success' => false, 'error' => "Erreur de connexion a l'API : " . $curlError]);
    exit;
}

http_response_code($httpCode ?: 500);
echo $response;
