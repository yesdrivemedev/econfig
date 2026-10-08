<?php
// Mode de l'environnement : 'dev' ou 'prod'.
// A changer manuellement sur chaque serveur (comme DB_HOST dans api/config/database.php).
// En mode 'prod', seules les API prod repondront (elles n'existent pas encore).
define('APP_MODE', 'dev');

// Identifiants sensibles (cles API) : voir secrets.php, qui n'est pas versionne
// (cf. .gitignore). Se baser sur secrets.php.example pour creer le fichier sur
// chaque environnement.
require_once __DIR__ . '/secrets.php';

// URL de base de l'api/ REST pour chaque mode.
// A ajuster selon l'emplacement reel du dossier api/ sur le serveur.
define('API_BASE_URL_DEV', 'http://192.168.4.160:8000/app_sceingconfigoffres/configuration/api');
define('API_BASE_URL_PROD', 'http://a-definir/api');

if (APP_MODE === 'prod') {
    define('API_BASE_URL', API_BASE_URL_PROD);
    define('API_KEY', API_KEY_PROD);
} else {
    define('API_BASE_URL', API_BASE_URL_DEV);
    define('API_KEY', API_KEY_DEV);
}
