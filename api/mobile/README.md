# API Mobile — Création et consultation d'offres

Endpoints PHP 5.4 + OCI8 pour automatiser la création de plans tarifaires Mobile dans Oracle.

Les fichiers `config/` et `includes/` à la racine de `api/` sont partagés avec les autres technologies.

---

## Structure des fichiers

```
api/mobile/
├── create_rateplan.php  ← création RATEPLAN + RATEPLAN_VERSION (+ options par défaut)
├── create_formule.php   ← création des formules (MPUSPTAB + MPULKPXN + TSP)
├── check_formule.php    ← vérification existence d'une formule dans MPUSPTAB
├── create_billing.php   ← paramétrage facturation (MPULKTM1 + MPULKTM2 par formule)
├── get_offre.php        ← consultation d'une offre avec ses formules et prix
└── options_data.php     ← données statiques (options par défaut — utilisé en interne)
```

---

## Flux de création recommandé

```
1. create_rateplan.php  → RATEPLAN + RATEPLAN_VERSION (VSCODE=0, STATUS='W')
                          retourne : tmcode
                          option : add_options=1 insère aussi 48 TSP + 90 TM1 + 29 TM2

2. create_formule.php   → MPUSPTAB + MPULKPXN (×15 SNCODEs) + TARIFF_SERVICE_PACKAGE
                          retourne : liste des spcodes créés

3. create_billing.php   → MPULKTM1 (×15) + MPULKTM2 (×14) par formule
                          le prix est porté par SNCODE 191 dans MPULKTM1
```

---

## Endpoints

---

### 1. Créer le plan tarifaire

**POST** `/mobile/create_rateplan.php`

Insère dans **RATEPLAN** puis **RATEPLAN_VERSION** (VSCODE=0, STATUS='W') dans la même transaction. Le TMCODE est généré automatiquement via `MAX(TMCODE)+1`.

Si `add_options=1`, insère également dans la même transaction les **options par défaut** du plan mobile :
- 48 lignes dans **TARIFF_SERVICE_PACKAGE** (TMVSCODE=0)
- 90 lignes dans **MPULKTM1** (VSCODE=0, STATUS='W')
- 29 lignes dans **MPULKTM2** (VSCODE=0, STATUS='W')

#### Corps JSON — champs requis

| Champ | Type | Description |
|-------|------|-------------|
| `des` | string | Description longue (ex: `"Mobile Confort"`) |
| `shdes` | string | Description courte unique (ex: `"MOBC"`) |
| `des_fr` | string | Segment marché : `"B2C"`, `"B2B"`, `"TEST"`, ... |
| `vsdate` | string | Date de version `dd-mm-yyyy` (ex: `"27-12-2025"`) |
| `apdate` | string | Date d'application `dd-mm-yyyy hh:mm:ss` (ex: `"27-12-2025 10:24:32"`) |
| `extrnl_rateplan_id` | int | Identifiant externe (ex: `1`=postpaid, `2`=prepaid) |

#### Corps JSON — champs optionnels

| Champ | Défaut | Description |
|-------|--------|-------------|
| `payment_method_ind` | `"P"` | Indicateur mode de paiement |
| `charging_engine_code` | `1` | Code moteur de facturation |
| `add_options` | `0` | `0` : rateplan seul ; `1` : insère aussi les options par défaut (48 TSP + 90 TM1 + 29 TM2) |

#### Exemple de corps
```json
{
  "des":                "Mobile Confort",
  "shdes":              "MOBC",
  "des_fr":             "B2C",
  "vsdate":             "27-12-2025",
  "apdate":             "27-12-2025 10:24:32",
  "extrnl_rateplan_id": 1,
  "add_options":        1
}
```

#### Réponse (HTTP 201)
```json
{
  "success": true,
  "tmcode":  861,
  "message": "Plan tarifaire cree avec succes"
}
```

---

### 2. Vérifier l'existence d'une formule

**GET** `/mobile/check_formule.php`

Vérifie si une formule existe déjà dans **MPUSPTAB**. Au moins un paramètre requis.

| Paramètre | Description |
|-----------|-------------|
| `des` | Nom de la formule |
| `shdes` | Description courte (format `D_***`, 5 caractères) |

**Exemple :**
```
GET /mobile/check_formule.php?shdes=D_M11
GET /mobile/check_formule.php?des=BR%20MIX%203&shdes=D_M11
```

#### Réponse (HTTP 200)
```json
{
  "success": true,
  "checks": {
    "des":   { "value": "BR MIX 3", "exists": false },
    "shdes": { "value": "D_M11",    "exists": false }
  }
}
```

---

### 3. Créer les formules

**POST** `/mobile/create_formule.php`

Crée une ou plusieurs formules (1 à 20) et les rattache au plan tarifaire. Pour chaque formule :
1. INSERT **MPUSPTAB** — création (SPCODE auto via `MAX+1`)
2. INSERT **MPULKPXN** × 15 — services fixes (SNCODEs : 1, 2, 3, 4, 5, 6, 8, 9, 10, 11, 50, 52, 53, 61, 191)
3. INSERT **TARIFF_SERVICE_PACKAGE** — rattachement au rateplan (TMVSCODE=0)

Toutes les formules sont créées dans la **même transaction** (rollback global sur erreur).

#### Corps JSON — champs requis

| Champ | Type | Description |
|-------|------|-------------|
| `tmcode` | int | Plan tarifaire auquel rattacher les formules |
| `formules` | array | Liste des formules (1 à 20) |

**Structure de chaque élément `formules` :**

| Champ | Type | Description |
|-------|------|-------------|
| `des` | string | Nom de la formule, unique dans MPUSPTAB |
| `shdes` | string | Code court, 5 caractères, format `D_***` (ex: `"D_M11"`) |

#### Exemple de corps (2 formules)
```json
{
  "tmcode": 861,
  "formules": [
    { "des": "BR MIX 3", "shdes": "D_M11" },
    { "des": "BR MIX 6", "shdes": "D_M12" }
  ]
}
```

#### Réponse (HTTP 201)
```json
{
  "success": true,
  "tmcode": 861,
  "formules": [
    { "spcode": 1454, "des": "BR MIX 3", "shdes": "D_M11" },
    { "spcode": 1455, "des": "BR MIX 6", "shdes": "D_M12" }
  ],
  "message": "2 formule(s) creee(s) avec succes"
}
```

---

### 4. Paramétrer la facturation

**POST** `/mobile/create_billing.php`

Paramètre la facturation d'une ou plusieurs formules. Pour chaque formule (`spcode`) :
- 15 lignes dans **MPULKTM1** (SNCODEs : 1, 2, 3, 4, 5, 6, 8, 9, 10, 11, 50, 52, 53, 61, 191)
- 14 lignes dans **MPULKTM2** (mêmes SNCODEs, sauf 191)

VSCODE=0, STATUS='W' fixés. L'`accessfee` est porté uniquement par SNCODE 191 dans MPULKTM1 ; tous les autres SNCODEs ont ACCESSFEE=0.

#### Corps JSON — champs requis

| Champ | Type | Description |
|-------|------|-------------|
| `tmcode` | int | Plan tarifaire |
| `vsdate` | string | Date de version `dd-mm-yyyy` (ex: `"27-12-2025"`) |
| `formules` | array | Liste des formules à facturer |

**Structure de chaque élément `formules` :**

| Champ | Type | Description |
|-------|------|-------------|
| `spcode` | int | Code de la formule (retourné par `create_formule.php`) |
| `accessfee` | int | Prix mensuel en centimes (ex: `2119` = 21,19 €) |

#### Exemple de corps
```json
{
  "tmcode": 861,
  "vsdate": "27-12-2025",
  "formules": [
    { "spcode": 1454, "accessfee": 2119 },
    { "spcode": 1455, "accessfee": 4238 }
  ]
}
```

#### Réponse (HTTP 201)
```json
{
  "success":         true,
  "tmcode":          861,
  "lignes_mpulktm1": 30,
  "lignes_mpulktm2": 28,
  "message":         "Facturation de 2 formule(s) parametree(s) avec succes"
}
```

> `lignes_mpulktm1` = 15 × nombre de formules, `lignes_mpulktm2` = 14 × nombre de formules.

---

### 5. Consulter une offre

**GET** `/mobile/get_offre.php`

Retourne les informations du plan tarifaire avec la liste de ses formules et le prix de chacune (ACCESSFEE du SNCODE 191 dans MPULKTM1). Exactement un seul paramètre de recherche.

| Paramètre | Description |
|-----------|-------------|
| `tmcode` | Code numérique du plan |
| `des` | Description longue |
| `shdes` | Description courte |

**Exemples :**
```
GET /mobile/get_offre.php?tmcode=861
GET /mobile/get_offre.php?shdes=MOBC
```

#### Réponse (HTTP 200)
```json
{
  "success": true,
  "offre": {
    "tmcode": 861,
    "des":    "Mobile Confort",
    "shdes":  "MOBC",
    "des_fr": "B2C",
    "formules": [
      {
        "spcode":    1454,
        "des":       "BR MIX 3",
        "shdes":     "D_M11",
        "accessfee": 2119,
        "status":    "W",
        "vscode":    0
      },
      {
        "spcode":    1455,
        "des":       "BR MIX 6",
        "shdes":     "D_M12",
        "accessfee": 4238,
        "status":    "W",
        "vscode":    0
      }
    ]
  }
}
```

---

## Exemples cURL

```bash
# 1. Créer le plan avec les options par défaut
curl -X POST \
  -H "X-API-Key: VOTRE_CLE" \
  -H "Content-Type: application/json" \
  -d '{
    "des":                "Mobile Confort",
    "shdes":              "MOBC",
    "des_fr":             "B2C",
    "vsdate":             "27-12-2025",
    "apdate":             "27-12-2025 10:24:32",
    "extrnl_rateplan_id": 1,
    "add_options":        1
  }' \
  "http://votre-serveur/api/mobile/create_rateplan.php"

# 2. Vérifier qu'une formule n'existe pas encore
curl -H "X-API-Key: VOTRE_CLE" \
  "http://votre-serveur/api/mobile/check_formule.php?shdes=D_M11"

# 3. Créer les formules (rattachées au tmcode 861)
curl -X POST \
  -H "X-API-Key: VOTRE_CLE" \
  -H "Content-Type: application/json" \
  -d '{
    "tmcode": 861,
    "formules": [
      { "des": "BR MIX 3", "shdes": "D_M11" },
      { "des": "BR MIX 6", "shdes": "D_M12" }
    ]
  }' \
  "http://votre-serveur/api/mobile/create_formule.php"

# 4. Paramétrer la facturation (spcode retournés par l'étape 3)
curl -X POST \
  -H "X-API-Key: VOTRE_CLE" \
  -H "Content-Type: application/json" \
  -d '{
    "tmcode": 861,
    "vsdate": "27-12-2025",
    "formules": [
      { "spcode": 1454, "accessfee": 2119 },
      { "spcode": 1455, "accessfee": 4238 }
    ]
  }' \
  "http://votre-serveur/api/mobile/create_billing.php"

# 5. Consulter l'offre créée
curl -H "X-API-Key: VOTRE_CLE" \
  "http://votre-serveur/api/mobile/get_offre.php?tmcode=861"
```
