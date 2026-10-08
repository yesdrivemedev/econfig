<?php
// Nom de cookie et cle de session propres a e-Config, pour eviter toute
// collision avec d'autres applications hebergees sur le meme serveur
// (ex: lsi) qui utilisent sinon toutes le cookie "PHPSESSID" par defaut —
// une session ouverte dans l'une se retrouverait alors active dans l'autre.
define('APP_SESSION_NAME', 'ECONFIG_SESSID');
define('APP_SESSION_LOGIN_KEY', 'econfig_login');

function appSessionStart()
{
    if (session_status() === PHP_SESSION_NONE) {
        session_name(APP_SESSION_NAME);
        session_start();
    }
}
