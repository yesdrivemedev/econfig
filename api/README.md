# API Télécoms — Création et consultation d'offres

API PHP 5.4 + OCI8 pour automatiser la création de plans tarifaires dans Oracle.

Technologies couvertes : **FTTH** · **Mobile** · **4G** · **Recharges**

---

## Structure des fichiers

```
api/
├── config/
│   ├── database.php               ← paramètres connexion Oracle (à renseigner)
│   └── app.php                    ← clé API secrète (à changer)
├── includes/
│   ├── db.php                     ← getConnection(), fetchScalar(), executeInsert(), ociType()
│   ├── auth.php                   ← vérification X-API-Key
│   └── response.php               ← sendResponse(), getRequestBody(), requireFields()
├── ftth/
│   ├── create_offre.php           ← création complète bout en bout (recommandé)
│   ├── check_rateplan.php         ← vérification existence d'un plan
│   ├── create_rateplan.php        ← insertion RATEPLAN + RATEPLAN_VERSION
│   ├── create_mpulktmb.php        ← insertion MPUPVTAB + MPULKTM1 (6 lignes) + TSP
│   ├── create_pricing.php         ← insertion MPULKPV1 + MPULKPV2 (plusieurs pricings)
│   └── get_offer.php              ← consultation d'une offre existante
├── mobile/                        ← voir mobile/README.md
│   ├── create_offre.php           ← création complète bout en bout (recommandé)
│   ├── create_rateplan.php        ← création RATEPLAN + RATEPLAN_VERSION (+ options par défaut)
│   ├── create_formule.php         ← création des formules (MPUSPTAB + MPULKPXN + TSP)
│   ├── check_formule.php          ← vérification existence d'une formule dans MPUSPTAB
│   ├── create_billing.php         ← paramétrage facturation (MPULKTM1 + MPULKTM2 par formule)
│   ├── get_offre.php              ← consultation d'une offre avec ses formules et prix
│   └── options_data.php           ← données statiques (options par défaut — utilisé en interne)
├── 4g/
│   ├── create_offre.php           ← création complète bout en bout (recommandé)
│   ├── create_rateplan.php        ← création RATEPLAN + RATEPLAN_VERSION (+ options par défaut + easybox)
│   ├── create_formule.php         ← création des formules (MPUSPTAB + MPULKPXN)
│   └── create_billing.php         ← rattachement TSP + facturation MPULKTM1 + rating MPULKTM2
└── recharges/
    ├── create_recharge.php        ← création type de recharge + configurations tarifaires
    └── link_rateplan.php          ← association recharge ↔ rateplan (wimax_config)
```

---

## Configuration

Renseigner avant tout déploiement :

**`config/database.php`**
```php
define('DB_HOST', 'localhost');
define('DB_PORT', '1521');
define('DB_SID',  'ORCL');
define('DB_USER', 'votre_utilisateur');
define('DB_PASS', 'votre_mot_de_passe');
```

**`config/app.php`**
```php
define('API_KEY', 'CHANGEZ_CETTE_CLE_API_SECRETE');
```

---

## Authentification

Chaque requête doit inclure le header :
```
X-API-Key: VOTRE_CLE_API
```
Réponse en cas de clé manquante ou invalide : **HTTP 401**

---

## Codes HTTP retournés

| Code | Signification |
|------|---------------|
| 200  | Succès (GET) |
| 201  | Création réussie (POST) |
| 400  | Paramètre manquant ou format invalide |
| 401  | Clé API invalide ou absente |
| 404  | Ressource introuvable |
| 405  | Méthode HTTP non autorisée |
| 409  | Conflit — libellé déjà existant |
| 500  | Erreur Oracle (message inclus dans la réponse) |

---

## FTTH

### Utilisation recommandée

#### Option A — Appel unique (recommandé)

Un seul appel, une seule transaction : tout est créé ou rien ne l'est.

```
POST create_offre.php
  └─ RATEPLAN
  └─ RATEPLAN_VERSION
  └─ MPUPVTAB
  └─ TARIFF_SERVICE_PACKAGE (SPCODE=1205)
  └─ [cesar=1] TARIFF_SERVICE_PACKAGE (SPCODE=1532)
  └─ MPULKTM1 × 6
  └─ [cesar=1] MPULKTM1 (SPCODE=1532, SNCODE=986)
  └─ MPULKPV1 × N  (1 par pricing)
  └─ MPULKPV2 × 6N (6 par pricing)
  └─ [cesar=1] MPULKPV1 + MPULKPV2 (pricing César, PARAMETER_ID=479)
     → COMMIT global
```

#### Option B — Appels séparés (contrôle étape par étape)

```
1. check_rateplan.php   → vérifier l'absence de doublons (GET)
2. create_rateplan.php  → RATEPLAN + RATEPLAN_VERSION          retourne : tmcode
3. create_mpulktmb.php  → TSP + MPUPVTAB + MPULKTM1 (6 lignes) retourne : pv_combi_id
4. create_pricing.php   → MPULKPV1 + MPULKPV2 (par pricing)
```

### Endpoints

---

#### 0. Création complète en un seul appel *(recommandé)*

**POST** `/ftth/create_offre.php`

#### Corps JSON — champs requis

| Champ | Type | Description |
|-------|------|-------------|
| `des` | string | Description longue du plan (ex: `"FTTR Confort"`) |
| `shdes` | string | Description courte, doit être unique (ex: `"FTTRC"`) |
| `des_fr` | string | Segment marché : `"B2C"`, `"B2B"`, `"TEST"`, ... |
| `vsdate` | string | Date de version `dd-mm-yyyy` (ex: `"01-09-2025"`) |
| `apdate` | string | Date d'application `dd-mm-yyyy hh:mm:ss` (ex: `"18-09-2025 10:24:32"`) |
| `extrnl_rateplan_id` | int | Identifiant externe (ex: `1`=postpaid, `2`=prepaid) |
| `formule` | string | Intitulé commercial commun → paramètre 448 (ex: `"FTTR CONFORT"`) |
| `debit` | string | Débit montant commun → paramètre 449 (ex: `"300M"`) |
| `debit_montant` | string | Débit upload commun → paramètre 450 (ex: `"30M"`) |
| `debit_descendant` | string | Débit descendant commun → paramètre 451 (ex: `"300M"`) |
| `volume` | string | Volume inclus commun → paramètre 452 (ex: `"4To"`) |
| `pricings` | array | Liste des pricings (au moins un élément) |

**Structure de chaque élément `pricings` :**

| Champ | Type | Description |
|-------|------|-------------|
| `set_id` | int | Identifiant du set tarifaire (ex: `1`, `2`, `3`) |
| `des` | string | Intitulé dans MPULKPV1 (ex: `"FTTR CONFORT"`) |
| `accessfee` | float | Montant de l'abonnement (ex: `33058`) |
| `duree` | string | Durée d'engagement → paramètre 453 (ex: `"Illimite"`, `"3 MOIS"`) |

#### Corps JSON — champs optionnels

| Champ | Défaut | Description |
|-------|--------|-------------|
| `payment_method_ind` | `"P"` | Indicateur mode de paiement |
| `charging_engine_code` | `1` | Code moteur de facturation |
| `cesar` | `0` | `1` : associe la formule César (SPCODE=1532) — TSP + MPULKTM1 + MPULKPV1 + MPULKPV2 |

**Champs requis si `cesar=1` :**

| Champ | Type | Description |
|-------|------|-------------|
| `cesar_des` | string | Libellé du pricing César (ex: `"FTTH CESAR"`) → MPULKPV1.DES et MPULKPV2.PRM_VALUE_DES |
| `cesar_accessfee` | float | Montant de l'abonnement César (ex: `33058`) → MPULKPV1.ACCESSFEE |
| `cesar_prm_value_string` | string | Valeur du paramètre 479 (ex: `"CESAR_VALUE"`) → MPULKPV2.PRM_VALUE_STRING |

#### Exemple de corps (2 pricings)
```json
{
  "des":              "FTTR Confort",
  "shdes":            "FTTRC",
  "des_fr":           "B2C",
  "vsdate":           "01-09-2025",
  "apdate":           "18-09-2025 10:24:32",
  "extrnl_rateplan_id": 1,
  "formule":          "FTTR CONFORT",
  "debit":            "300M",
  "debit_montant":    "30M",
  "debit_descendant": "300M",
  "volume":           "4To",
  "pricings": [
    { "set_id": 1, "des": "FTTR CONFORT",    "accessfee": 33058, "duree": "Illimite" },
    { "set_id": 2, "des": "FTTR CONFORT 3M", "accessfee": 99000, "duree": "3 MOIS"  }
  ]
}
```

#### Réponse — succès (HTTP 201)
Sans César (`cesar=0`) :
```json
{
  "success":     true,
  "tmcode":      1285,
  "pv_combi_id": 2981,
  "etapes": {
    "rateplan":                "OK",
    "rateplan_version":        "OK",
    "mpupvtab":                "OK",
    "tariff_service_package":  "OK",
    "mpulktm1":                "OK (6 lignes)",
    "mpulkpv1":                "OK (2 lignes)",
    "mpulkpv2":                "OK (12 lignes)"
  },
  "message": "Offre creee avec succes"
}
```

Avec César (`cesar=1`) :
```json
{
  "success":          true,
  "tmcode":           1285,
  "pv_combi_id":      2981,
  "cesar_pv_combi_id": 2982,
  "etapes": {
    "rateplan":                     "OK",
    "rateplan_version":             "OK",
    "mpupvtab":                     "OK",
    "tariff_service_package":       "OK",
    "tariff_service_package_cesar": "OK",
    "mpulktm1":                     "OK (6 lignes)",
    "mpulktm1_cesar":               "OK",
    "mpulkpv1":                     "OK (2 lignes)",
    "mpulkpv2":                     "OK (12 lignes)",
    "mpulkpv1_cesar":               "OK",
    "mpulkpv2_cesar":               "OK"
  },
  "message": "Offre creee avec succes"
}
```

---

#### 1. Vérifier l'existence d'un plan

**GET** `/ftth/check_rateplan.php`

| Paramètre | Description |
|-----------|-------------|
| `tmcode` | Code numérique du plan |
| `des` | Description longue |
| `shdes` | Description courte |

**Exemple :**
```
GET /ftth/check_rateplan.php?tmcode=1284&shdes=FTTRC
```

#### Réponse (HTTP 200)
```json
{
  "success": true,
  "checks": {
    "tmcode": { "value": 1284,    "exists": false },
    "shdes":  { "value": "FTTRC", "exists": false }
  }
}
```

---

#### 2. Créer le plan tarifaire

**POST** `/ftth/create_rateplan.php`

Insère dans **RATEPLAN** puis **RATEPLAN_VERSION** (VSCODE=0, STATUS='W'). Le TMCODE est généré depuis `sysadm.app_sequence_value` (app_sequence_id=68).

**Champs requis :**

| Champ | Type | Description |
|-------|------|-------------|
| `des` | string | Description longue |
| `shdes` | string | Description courte unique |
| `des_fr` | string | Segment marché |
| `vsdate` | string | Date de version `dd-mm-yyyy` |
| `apdate` | string | Date d'application `dd-mm-yyyy hh:mm:ss` |
| `extrnl_rateplan_id` | int | Identifiant externe |

**Champs optionnels :**

| Champ | Défaut | Description |
|-------|--------|-------------|
| `cesar` | `0` | `1` : associe la formule César (SPCODE=1532) dans la même transaction |

**Champs requis si `cesar=1` :**

| Champ | Type | Description |
|-------|------|-------------|
| `cesar_des` | string | Libellé du pricing César |
| `cesar_accessfee` | float | Montant de l'abonnement César |
| `cesar_prm_value_string` | string | Valeur du paramètre 479 |

#### Réponse (HTTP 201)
```json
{ "success": true, "tmcode": 1285, "message": "Plan tarifaire cree avec succes" }
```
Avec `cesar=1` : la réponse inclut également `"cesar_pv_combi_id": 2981`.

---

#### 3. Insérer TSP + MPUPVTAB + MPULKTM1

**POST** `/ftth/create_mpulktmb.php`

Insère dans **TARIFF_SERVICE_PACKAGE** (SPCODE=1205), **MPUPVTAB** (réservation PV_COMBI_ID via MAX+1), puis les **6 lignes fixes** dans **MPULKTM1** (SNCODE : 573, 574, 857, 858, 859, 861).

| Champ | Type | Description |
|-------|------|-------------|
| `tmcode` | int | TMCODE retourné par `create_rateplan.php` |
| `vsdate` | string | Date de version `dd-mm-yyyy` |

#### Réponse (HTTP 201)
```json
{
  "success": true, "tmcode": 1285, "pv_combi_id": 2981,
  "lignes_inserees": 6, "message": "Insertion MPULKTM1 effectuee avec succes"
}
```

---

#### 4. Insérer les prix et paramètres

**POST** `/ftth/create_pricing.php`

Insère dans **MPULKPV1** (1 ligne par pricing) puis **MPULKPV2** (6 paramètres par pricing).

| Champ | Type | Description |
|-------|------|-------------|
| `pv_combi_id` | int | PV_COMBI_ID retourné par `create_mpulktmb.php` |
| `formule` | string | Paramètre 448 — commun |
| `debit` | string | Paramètre 449 — commun |
| `debit_montant` | string | Paramètre 450 — commun |
| `debit_descendant` | string | Paramètre 451 — commun |
| `volume` | string | Paramètre 452 — commun |
| `pricings` | array | `[{ set_id, des, accessfee, duree }]` |

#### Réponse (HTTP 201)
```json
{
  "success": true, "pv_combi_id": 2981,
  "lignes_mpulkpv1": 2, "lignes_mpulkpv2": 12,
  "message": "Insertion pricing effectuee avec succes"
}
```

---

#### 5. Consulter une offre existante

**GET** `/ftth/get_offer.php`

| Paramètre | Description |
|-----------|-------------|
| `tmcode` | Code numérique du plan |
| `des` | Description longue |
| `shdes` | Description courte |

### Règles de format des paramètres MPULKPV2

| PARAMETER_ID | PRM_DES | PRM_VALUE_STRING | PRM_VALUE_DES |
|---|---|---|---|
| 448 | 01_Formule | `<formule>` | `<formule>` |
| 449 | 02_Debit | `<debit>\|1` | `<debit>` |
| 450 | 03_Debit Montant | `<debit_montant>\|1` | `<debit_montant>` |
| 451 | 04_Debit Descendant | `<debit_descendant>\|1` | `<debit_descendant>` |
| 452 | 05_Volume | `<volume>\|1` | `<volume>` |
| 453 | 06_Duree | `<duree>` | `<duree>` |

---

## Mobile

Documentation complète : [mobile/README.md](mobile/README.md)

### Utilisation recommandée

#### Option A — Appel unique (recommandé)

```
POST create_offre.php
  └─ RATEPLAN
  └─ RATEPLAN_VERSION
  └─ 48 TSP + 90 MPULKTM1 + 29 MPULKTM2  (options par défaut, si add_options=1)
  └─ Pour chaque formule :
       MPUSPTAB + MPULKPXN × 15 + TSP + MPULKTM1 × 15 + MPULKTM2 × 14
     → COMMIT global
```

#### Option B — Appels séparés

```
1. create_rateplan.php  → RATEPLAN + RATEPLAN_VERSION        retourne : tmcode
2. check_formule.php    → vérification absence de doublons (GET)
3. create_formule.php   → MPUSPTAB + MPULKPXN × 15 + TSP    retourne : spcodes
4. create_billing.php   → MPULKTM1 × 15 + MPULKTM2 × 14 par formule
```

### Endpoints

| Méthode | Fichier | Description |
|---------|---------|-------------|
| POST | `mobile/create_offre.php`    | Création complète bout en bout (recommandé) |
| POST | `mobile/create_rateplan.php` | Crée le plan tarifaire (RATEPLAN + RATEPLAN_VERSION) ; `add_options=1` ajoute 48 TSP + 90 TM1 + 29 TM2 |
| GET  | `mobile/check_formule.php`   | Vérifie si un `des` ou `shdes` existe déjà dans MPUSPTAB |
| POST | `mobile/create_formule.php`  | Crée 1 à 20 formules (MPUSPTAB + MPULKPXN × 15 + TSP) |
| POST | `mobile/create_billing.php`  | Paramètre la facturation (MPULKTM1 × 15 + MPULKTM2 × 14 par formule) |
| GET  | `mobile/get_offre.php`       | Consulte une offre avec ses formules et prix |

### Corps JSON — `create_offre.php`

| Champ | Type | Description |
|-------|------|-------------|
| `des` | string | Description longue du plan (ex: `"Mobile Confort"`) |
| `shdes` | string | Description courte unique (ex: `"MOBC"`) |
| `des_fr` | string | Segment marché : `"B2C"`, `"B2B"`, `"TEST"`, ... |
| `vsdate` | string | Date de version `dd-mm-yyyy` |
| `apdate` | string | Date d'application `dd-mm-yyyy hh:mm:ss` |
| `extrnl_rateplan_id` | int | Identifiant externe (ex: `1`=postpaid, `2`=prepaid) |
| `formules` | array | Liste des formules (1 à 20) — voir structure ci-dessous |
| `add_options` | int | `1` (défaut) : insère 48 TSP + 90 TM1 + 29 TM2 par défaut ; `0` : sans |

**Structure de chaque élément `formules` :**

| Champ | Type | Description |
|-------|------|-------------|
| `des` | string | Nom unique de la formule (ex: `"BR MIX 3"`) |
| `shdes` | string | 5 caractères, format `D_***` (ex: `"D_M11"`) |
| `accessfee` | int | Prix en centimes, porté par SNCODE 191 dans MPULKTM1 (ex: `2119`) |

### Exemple cURL — `create_offre.php`

```bash
curl -X POST \
  -H "X-API-Key: VOTRE_CLE" \
  -H "Content-Type: application/json" \
  -d '{
    "des": "Mobile Confort", "shdes": "MOBC", "des_fr": "B2C",
    "vsdate": "27-12-2025", "apdate": "27-12-2025 10:24:32",
    "extrnl_rateplan_id": 1,
    "add_options": 1,
    "formules": [
      { "des": "BR MIX 3", "shdes": "D_M11", "accessfee": 2119 },
      { "des": "BR MIX 6", "shdes": "D_M12", "accessfee": 4237 }
    ]
  }' \
  "http://votre-serveur/api/mobile/create_offre.php"
```

### Réponse — succès (HTTP 201)
```json
{
  "success": true,
  "tmcode": 861,
  "formules": [
    { "spcode": 1454, "des": "BR MIX 3", "shdes": "D_M11" },
    { "spcode": 1455, "des": "BR MIX 6", "shdes": "D_M12" }
  ],
  "etapes": {
    "rateplan":         "OK",
    "rateplan_version": "OK",
    "options":          "OK (48 TSP, 90 TM1, 29 TM2)",
    "formules":         "OK (2 formule(s), 15 PXN + 1 TSP + 15 TM1 + 14 TM2 par formule)"
  },
  "message": "Offre mobile creee avec succes"
}
```

---

## 4G

### Utilisation recommandée

#### Option A — Appel unique (recommandé)

```
POST create_offre.php
  └─ RATEPLAN
  └─ RATEPLAN_VERSION
  └─ 2 TSP + 2 MPULKTM1       (options par défaut, si add_options=1)
  └─ 1 TSP + 1 MPULKTM1       (option Easybox SPCODE=1373, si easybox=1)
  └─ Pour chaque formule :
       MPUSPTAB + MPULKPXN × 6 + TSP + MPULKTM1 × 6 + MPULKTM2 × 3
     → COMMIT global
```

#### Option B — Appels séparés

```
1. create_rateplan.php  → RATEPLAN + RATEPLAN_VERSION   retourne : tmcode
2. create_formule.php   → MPUSPTAB + MPULKPXN × 6       retourne : spcodes
3. create_billing.php   → TSP + MPULKTM1 × 6 + MPULKTM2 × 3 par formule
```

### Endpoints

| Méthode | Fichier | Description |
|---------|---------|-------------|
| POST | `4g/create_offre.php`    | Création complète bout en bout (recommandé) |
| POST | `4g/create_rateplan.php` | Crée le plan tarifaire ; `add_options=1` ajoute 2 TSP + 2 TM1 ; `easybox=1` ajoute SPCODE 1373 |
| POST | `4g/create_formule.php`  | Crée 1 à 20 formules (MPUSPTAB + MPULKPXN × 6) ; SHDES = 5 caractères |
| POST | `4g/create_billing.php`  | TSP + MPULKTM1 × 6 + MPULKTM2 × 3 par formule |

### Corps JSON — `create_offre.php`

| Champ | Type | Description |
|-------|------|-------------|
| `des` | string | Description longue du plan (ex: `"4G Office Hyb 500Go"`) |
| `shdes` | string | Description courte unique (ex: `"4OfG5"`) |
| `des_fr` | string | Segment marché : `"B2C"`, `"B2B"`, `"TEST"`, ... |
| `vsdate` | string | Date de version `dd-mm-yyyy` |
| `apdate` | string | Date d'application `dd-mm-yyyy hh:mm:ss` |
| `extrnl_rateplan_id` | int | Identifiant externe (ex: `1`=postpaid, `2`=prepaid) |
| `formules` | array | Liste des formules (1 à 20) — voir structure ci-dessous |
| `add_options` | int | `0` (défaut) : sans options par défaut ; `1` : 2 TSP + 2 MPULKTM1 |
| `easybox` | int | `0` (défaut) : sans Easybox ; `1` : TSP + MPULKTM1 SPCODE=1373 |

**Structure de chaque élément `formules` :**

| Champ | Type | Description |
|-------|------|-------------|
| `des` | string | Nom unique de la formule (ex: `"4G Office Hyb 500Go"`) |
| `shdes` | string | Exactement 5 caractères (ex: `"4O500"`) |
| `accessfee` | int | Prix en centimes, porté par SNCODE 749 dans MPULKTM1 (ex: `37190`) |

### Exemple cURL — `create_offre.php`

```bash
curl -X POST \
  -H "X-API-Key: VOTRE_CLE" \
  -H "Content-Type: application/json" \
  -d '{
    "des": "4G Office Hyb 500Go", "shdes": "4OfG5", "des_fr": "B2B",
    "vsdate": "19-03-2024", "apdate": "18-03-2024 20:20:22",
    "extrnl_rateplan_id": 1,
    "add_options": 1,
    "easybox": 0,
    "formules": [
      { "des": "4G Office Hyb 500Go", "shdes": "4O500", "accessfee": 37190 }
    ]
  }' \
  "http://votre-serveur/api/4g/create_offre.php"
```

### Réponse — succès (HTTP 201)
```json
{
  "success": true,
  "tmcode": 1256,
  "formules": [
    { "spcode": 1826, "des": "4G Office Hyb 500Go", "shdes": "4O500" }
  ],
  "etapes": {
    "rateplan":         "OK",
    "rateplan_version": "OK",
    "options":          "OK (2 TSP, 2 TM1)",
    "formules":         "OK (1 formule(s), 6 PXN + 1 TSP + 6 TM1 + 3 TM2 par formule)"
  },
  "message": "Offre 4G creee avec succes"
}
```

---

## Recharges

### Flux de création recommandé

```
1. create_recharge.php  → alcatel.recharge_types + alcatel.recharge_config (× N montants)
                          DUREE auto-incrémenté (1 à N), VSCODE=1

2. link_rateplan.php    → alcatel.wimax_config (DES = RECHARGE_TYPE)
```

### Endpoints

| Méthode | Fichier | Description |
|---------|---------|-------------|
| POST | `recharges/create_recharge.php` | Crée le type de recharge et ses N configurations tarifaires |
| POST | `recharges/link_rateplan.php`   | Associe un type de recharge à un plan tarifaire (wimax_config) |

### Corps JSON — `create_recharge.php`

| Champ | Type | Description |
|-------|------|-------------|
| `recharge_type` | string | Libellé unique du type de recharge (ex: `"4G Home Flybox Prep"`) |
| `vsdate` | string | Date de version `dd-mm-yyyy` (ex: `"01-01-2022"`) |
| `montants` | array | Liste des montants float > 0, ordonnés par durée croissante |

DUREE est affecté automatiquement (1, 2, 3, …). VOLUME=`'NULL'`, VSCODE=1, TMCODE=NULL, DESCRIPTION=NULL.

#### Réponse (HTTP 201)
```json
{
  "success": true,
  "recharge_type": "4G Home Flybox Prep",
  "lignes_config": 5,
  "message": "Recharge parametree avec succes"
}
```

### Corps JSON — `link_rateplan.php`

| Champ | Type | Description |
|-------|------|-------------|
| `tmcode` | int | TMCODE du plan tarifaire (ex: `1119`) |
| `recharge_type` | string | Libellé existant dans `alcatel.recharge_types` |

#### Réponse (HTTP 201)
```json
{
  "success": true,
  "tmcode": 1119,
  "recharge_type": "4G Home Flybox Prep",
  "message": "Association recharge-rateplan creee avec succes"
}
```

### Exemple cURL

```bash
# 1. Créer le type de recharge avec 5 montants
curl -X POST -H "X-API-Key: VOTRE_CLE" -H "Content-Type: application/json" \
  -d '{
    "recharge_type": "4G Home Flybox Prep",
    "vsdate": "01-01-2022",
    "montants": [8474.58, 14406.78, 21186.44, 25423.73, 38135.59]
  }' "http://votre-serveur/api/recharges/create_recharge.php"

# 2. Associer au rateplan
curl -X POST -H "X-API-Key: VOTRE_CLE" -H "Content-Type: application/json" \
  -d '{ "tmcode": 1119, "recharge_type": "4G Home Flybox Prep" }' \
  "http://votre-serveur/api/recharges/link_rateplan.php"
```
