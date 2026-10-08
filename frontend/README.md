# Frontend — Configuration des Offres (e-Config)

Frontend PHP 5.4 + JavaScript, authentification LDAP (meme annuaire que le
projet `lsi`). Habillage base sur le template admin **Gentelella** (Bootstrap 3
+ jQuery, dossiers `vendors/` et `build/`). Consomme l'`api/` REST via un proxy
PHP cote serveur — le navigateur n'a jamais connaissance de la cle `X-API-Key`.

---

## Structure

```
frontend/
├── config/
│   ├── ldap.php              ← LDAP_SERVER, LDAP_DOMAIN_PREFIX
│   ├── app.php                ← APP_MODE ('dev'|'prod'), API_BASE_URL, API_KEY
│   ├── ftth.php                ← durees configurables pour FTTH Prepayee
│   ├── secrets.php            ← cles API (non versionne, voir .gitignore)
│   └── secrets.php.example    ← modele a copier en secrets.php
├── includes/
│   ├── session.php             ← nom de cookie/cle de session propres au projet (voir "Session")
│   ├── auth_check.php         ← garde de session (redirige vers $basePath.'login.php')
│   ├── menu.php                ← source unique du sidemenu (categorie > icone > sous-items)
│   ├── layout_header.php       ← <head>, sidemenu Gentelella, entete (jusqu'a <main>)
│   └── layout_footer.php       ← ferme <main>, injecte PROXY_URL, charge les scripts communs + $extraScripts
├── vendors/                   ← librairies tierces Gentelella (bower). Seul un sous-ensemble
│                                 est charge par defaut (voir "Librairies chargees" ci-dessous) ;
│                                 le reste est disponible mais pas charge tant que rien ne l'utilise.
├── build/                     ← css/js custom du template (mecanique sidebar, styles de base)
├── addons/
│   ├── js/
│   │   ├── config.js          ← callApi(), helpers de date, escapeHtml()
│   │   ├── mobile.js          ← logique de la page mobile Hybride (formules, DataTables, iCheck)
│   │   └── ftth.js            ← logique partagee postpaye/prepaye (creation, recherche, Cesar)
│   └── images/orange_ico.png, orange_2.png
├── login.php / logout.php     ← authentification LDAP (page de connexion au style Gentelella)
├── proxy.php                  ← proxy generique vers api/ (whitelist d'endpoints)
├── index.php                  ← page d'accueil (sidemenu, aucune offre selectionnee)
├── mobile/
│   └── hybride.php            ← offre Mobile Hybride (seule activee pour l'instant)
└── ftth/
    ├── postpaye.php           ← offre FTTH Postpayee (duree fixee a "Illimite")
    └── prepaye.php            ← offre FTTH Prepayee (duree au choix, voir config/ftth.php)
```

### Layout partage (sidemenu Gentelella)

Chaque page definit `$basePath` (chemin relatif vers la racine de `frontend/`,
ex: `''` pour `index.php`, `'../'` pour `mobile/hybride.php`) **avant**
d'inclure `includes/auth_check.php`, puis `includes/layout_header.php` /
`includes/layout_footer.php` encadrent le contenu specifique de la page :

```php
<?php
$basePath = '../';
require_once __DIR__ . '/../includes/auth_check.php';
$pageTitle = 'Mobile — Hybride';
$activeLink = 'mobile/hybride.php';           // doit correspondre au 'link' dans menu.php
$extraStyles = ['vendors/xxx/xxx.css'];       // optionnel, CSS specifique a la page
require_once __DIR__ . '/../includes/layout_header.php';
?>
... contenu de la page (dans x_content) ...
<?php
$extraScripts = ['vendors/xxx/xxx.js', 'addons/js/mobile.js']; // optionnel
require_once __DIR__ . '/../includes/layout_footer.php';
?>
```

Le sidemenu (categories + icones + sous-items, actif/desactive) est
entierement pilote par `includes/menu.php`. Activer une nouvelle offre =
changer `'link'` et `'enabled'` pour ce sous-item, rien d'autre a toucher dans
le layout.

`layout_footer.php` injecte aussi `PROXY_URL` en JS (`<?php echo $basePath; ?>proxy.php`,
donc toujours relatif a la page courante) avant de charger `config.js` : pas
d'IP/port/domaine code en dur, donc pas de risque de CORS ni de chemin casse
si le serveur ou le port changent.

### Librairies vendors chargees par defaut

Pour rester leger, seul le socle necessaire au sidemenu/UI de base est charge
sur toutes les pages : `jquery`, `bootstrap`, `fastclick`, `nprogress`,
`iCheck` (cases a cocher stylees) + `build/css|js/custom.min.*` (mecanique du
sidebar, toggle, tooltips). Les ~40 autres vendors (charts, datepicker,
calendrier, dropzone, wysiwyg, cropper...) restent disponibles dans `vendors/`
mais ne sont charges qu'a la demande via `$extraStyles`/`$extraScripts` sur la
page qui en a besoin — voir `mobile/hybride.php` qui ajoute DataTables
(`datatables.net` + `datatables.net-bs`) pour le tableau de resultats.

---

## Configuration avant deploiement

1. Copier `config/secrets.php.example` en `config/secrets.php` et renseigner
   `API_KEY_DEV` avec la **meme valeur** que `API_KEY` dans
   `../api/config/app.php` sur le serveur dev.
2. `API_BASE_URL_DEV` dans `config/app.php` pointe vers
   `http://192.168.4.160:8000/app_sceingconfigoffres/configuration/api`.
   A ajuster si le dossier `api/`, l'IP ou le port changent.
   ⚠️ A verifier : `test.html` (gabarit d'origine) reference les assets du
   frontend via `192.168.4.146:8000`, une IP differente de celle utilisee ici
   pour `api/`. Si frontend et api sont bien sur deux serveurs distincts,
   c'est normal ; sinon, corriger `API_BASE_URL_DEV`.
3. Verifier `config/ldap.php` (memes valeurs que `lsi/API/config.php` — meme
   annuaire AD).

## Mode dev / prod

`APP_MODE` dans `config/app.php` selectionne l'environnement :
- `dev` : pointe vers `API_BASE_URL_DEV` / `API_KEY_DEV` — seules les API
  actuellement developpees (mobile Hybride) repondent.
- `prod` : pointe vers `API_BASE_URL_PROD` / `API_KEY_PROD` — a renseigner
  quand les API prod existeront.

La bascule se fait en changeant la constante sur le serveur concerne (pas de
toggle utilisateur).

## Session

`includes/session.php` centralise le nom du cookie de session
(`APP_SESSION_NAME` = `ECONFIG_SESSID`) et la cle de session
(`APP_SESSION_LOGIN_KEY` = `econfig_login`), a la place des valeurs par
defaut PHP (`PHPSESSID` / cle generique). Necessaire car plusieurs
applications (dont `lsi`) tournent sur le meme serveur : avec les noms par
defaut, le meme cookie est partage entre applications et une session ouverte
dans l'une se retrouve active dans l'autre. Toute page ou script qui a besoin
de la session doit passer par `appSessionStart()` (jamais `session_start()`
directement) et lire/ecrire `$_SESSION[APP_SESSION_LOGIN_KEY]`.

## Plan du sidemenu

| Technologie | Type d'offre | Statut |
|---|---|---|
| Mobile | Hybride (`extrnl_rateplan_id=3`) | Fonctionnel (`mobile/hybride.php`) |
| Mobile | Postpayee (`extrnl_rateplan_id=1`) | Grise, "Bientot disponible" |
| Mobile | Prepayee (`extrnl_rateplan_id=6`) | Grise, "Bientot disponible" |
| FTTH | Postpayee (`extrnl_rateplan_id=1`) | Fonctionnel (`ftth/postpaye.php`) |
| FTTH | Prepayee (`extrnl_rateplan_id=6`) | Fonctionnel (`ftth/prepaye.php`) |
| 4G | Hybride | Grise, "Bientot disponible" |
| 4G | Prepayee | Grise, "Bientot disponible" |

- `mobile/hybride.php` : formulaire de creation d'offre (`mobile/create_offre.php`
  via le proxy, tableau "Formules" dynamique, cases a cocher iCheck) +
  consultation d'une offre existante (`mobile/get_offre.php`), resultats via
  DataTables.
- `ftth/postpaye.php` / `ftth/prepaye.php` : formulaire de creation d'offre
  (`ftth/create_offre.php` via le proxy, un seul pricing par creation) +
  consultation d'une offre existante (`ftth/get_offer.php`), resultats via
  DataTables. Logique JS partagee dans `addons/js/ftth.js`, parametree par un
  petit objet `FTTH_OFFER_CONFIG` (`extrnlRateplanId`, `dureeFixed`) injecte en
  inline `<script>` par chaque page avant le chargement de `ftth.js`.
  - Postpayee : duree fixee a `"Illimite"` (non affichee dans le formulaire,
    comme `extrnl_rateplan_id` qui n'est jamais expose a l'utilisateur).
  - Prepayee : duree au choix parmi une liste configurable dans `config/ftth.php`
    (`getFtthPrepayeeDurees()` — actuellement `1MOIS, 3MOIS, 9MOIS, 12MOIS,
    2JOURS, 3JOURS, 7JOURS`).
  - `vsdate`/`apdate` sont pre-remplis a J+1 par `ftth.js` au chargement de la
    page, mais restent modifiables.
  - Option Cesar : case a cocher qui revele/rend requis les 3 champs
    `cesar_des`, `cesar_accessfee`, `cesar_prm_value_string` (sinon `cesar=0`,
    aucun champ envoye).

Les combinaisons restantes (Mobile Postpayee/Prepayee, 4G) seront ajoutees au
fur et a mesure que leurs API repondront (voir `includes/menu.php` pour les
activer).

## Securite du proxy

`proxy.php` n'accepte qu'une liste blanche d'endpoints `api/mobile/*.php`
(voir le tableau `$allowedEndpoints`). Toute requete necessite une session
active (`$_SESSION[APP_SESSION_LOGIN_KEY]`), sinon HTTP 401. Chaque appel est
journalise dans deux fichiers :
- `logs/proxy.log` — une ligne par appel (date, utilisateur, mode, methode +
  endpoint, code HTTP), pour un coup d'oeil rapide.
- `logs/proxy_detail.log` — le detail complet de chaque appel : URL exacte
  appelee sur `api/`, corps envoye (POST), code HTTP retourne ou erreur cURL,
  et les 5000 premiers caracteres de la reponse. N'inclut jamais `X-API-Key`
  (secret, jamais journalise).

Pour ouvrir de nouveaux endpoints (4G, Recharges, ou Prepayee/Postpayee mobile
une fois les API pretes), ajouter leur chemin a `$allowedEndpoints` dans
`proxy.php`.
