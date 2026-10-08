<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/response.php';

/**
 * Vérifie la clé API dans le header X-API-Key.
 * Arrête l'exécution avec 401 si invalide.
 */
function authenticate()
{
    $apiKey = '';

    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        // Normalisation des clés (certains serveurs changent la casse)
        foreach ($headers as $name => $value) {
            if (strtolower($name) === 'x-api-key') {
                $apiKey = $value;
                break;
            }
        }
    }

    // Fallback via $_SERVER pour les serveurs sans getallheaders()
    if ($apiKey === '' && isset($_SERVER['HTTP_X_API_KEY'])) {
        $apiKey = $_SERVER['HTTP_X_API_KEY'];
    }

    if ($apiKey === '' || $apiKey !== API_KEY) {
        sendResponse(401, ['success' => false, 'error' => 'Cle API invalide ou manquante']);
    }
}
