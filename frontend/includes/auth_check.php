<?php
// Garde de session pour les pages (redirection vers le login si non connecte).
// Chaque page doit definir $basePath (chemin relatif vers la racine de frontend/)
// avant d'inclure ce fichier.
require_once __DIR__ . '/session.php';
appSessionStart();
if (!isset($_SESSION[APP_SESSION_LOGIN_KEY])) {
    $redirectBase = isset($basePath) ? $basePath : '';
    header('location: ' . $redirectBase . 'login.php');
    exit;
}
