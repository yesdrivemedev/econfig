<?php

/**
 * Envoie une réponse JSON et arrête l'exécution.
 */
function sendResponse($httpCode, array $data)
{
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($httpCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

/**
 * Lit et décode le corps JSON de la requête entrante.
 * Arrête avec 400 si le JSON est invalide.
 */
function getRequestBody()
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        sendResponse(400, ['success' => false, 'error' => 'Corps de requete vide']);
    }

    $data = json_decode($raw, true);
    if ($data === null) {
        sendResponse(400, ['success' => false, 'error' => 'JSON invalide']);
    }

    return $data;
}

/**
 * Vérifie que les champs requis sont présents dans $data.
 * Arrête avec 400 si un champ est manquant.
 */
function requireFields(array $data, array $fields)
{
    $missing = [];
    foreach ($fields as $field) {
        if (!array_key_exists($field, $data) || $data[$field] === null || $data[$field] === '') {
            $missing[] = $field;
        }
    }

    if (!empty($missing)) {
        sendResponse(400, [
            'success' => false,
            'error'   => 'Champs obligatoires manquants : ' . implode(', ', $missing),
        ]);
    }
}
