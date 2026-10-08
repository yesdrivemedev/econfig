<?php
/**
 * GET /get_offer.php
 *
 * Retourne les informations complètes d'une offre FTTH.
 * Gère plusieurs pricings (SET_ID différents) par offre.
 *
 * Paramètres GET (exactement un requis) :
 *   tmcode (int)    — code du plan tarifaire
 *   des    (string) — description longue
 *   shdes  (string) — description courte
 *
 * Réponse :
 *   {
 *     "success": true,
 *     "offre": {
 *       "tmcode": 1171,
 *       "des":    "Fibre UP TV Max 1Gb",
 *       "shdes":  "Z2C1V",
 *       "pricings": [
 *         {
 *           "set_id":    1,
 *           "des":       "Fibre UP Starter 50M Prep",
 *           "parametres": [
 *             { "prm_des": "01_Formule",          "valeur": "Fibre UP Starter 50M Prep", "accessfee": 12711.86 },
 *             { "prm_des": "02_Debit",             "valeur": "50M" },
 *             { "prm_des": "03_Debit Montant",     "valeur": "8M"  },
 *             { "prm_des": "04_Debit Descendant",  "valeur": "50M" },
 *             { "prm_des": "05_Volume",            "valeur": "1.2To" },
 *             { "prm_des": "06_Duree",             "valeur": "1 MOIS" }
 *           ]
 *         },
 *         ...
 *       ]
 *     }
 *   }
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/response.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(405, ['success' => false, 'error' => 'Methode non autorisee. Utilisez GET.']);
}

authenticate();

$tmcode = isset($_GET['tmcode']) ? trim($_GET['tmcode']) : null;
$des    = isset($_GET['des'])    ? trim($_GET['des'])    : null;
$shdes  = isset($_GET['shdes'])  ? trim($_GET['shdes'])  : null;

// Un seul critère à la fois
$provided = array_filter([$tmcode, $des, $shdes], function ($v) { return $v !== null && $v !== ''; });
if (count($provided) === 0) {
    sendResponse(400, ['success' => false, 'error' => 'Un parametre de recherche est requis : tmcode, des ou shdes']);
}
if (count($provided) > 1) {
    sendResponse(400, ['success' => false, 'error' => 'Fournissez un seul parametre a la fois : tmcode, des ou shdes']);
}

// Clause WHERE dynamique selon le critère fourni
if ($tmcode !== null && $tmcode !== '') {
    $whereClause = 'ra.TMCODE = :val';
    $bindValue   = (int) $tmcode;
    $bindType    = SQLT_INT;
} elseif ($des !== null && $des !== '') {
    $whereClause = 'ra.DES = :val';
    $bindValue   = $des;
    $bindType    = SQLT_CHR;
} else {
    $whereClause = 'ra.SHDES = :val';
    $bindValue   = $shdes;
    $bindType    = SQLT_CHR;
}

// pvb.SET_ID et pvb.DES ajoutés pour grouper par pricing
// pvb.ACCESSFEE affiché au niveau du premier paramètre de chaque pricing
$sql = "SELECT
            ra.TMCODE,
            ra.DES,
            ra.SHDES,
            pvb.SET_ID,
            pvb.DES       AS PVB_DES,
            pvb.ACCESSFEE,
            pvm.PRM_VALUE_DES,
            mkt.PRM_DES
        FROM
            RATEPLAN       ra,
            MPULKTMB       tmb,
            MPULKPVB       pvb,
            MPULKPVM       pvm,
            MKT_PARAMETER  mkt
        WHERE
            " . $whereClause . "
            AND ra.TMCODE      = tmb.TMCODE
            AND tmb.VSCODE     = (
                SELECT MAX(t2.VSCODE)
                FROM MPULKTMB t2
                WHERE t2.TMCODE = tmb.TMCODE
                  AND t2.SNCODE = tmb.SNCODE
            )
            AND tmb.SNCODE         = 857
            AND tmb.PV_COMBI_ID    = pvb.PV_COMBI_ID
            AND tmb.VSCODE         = pvb.VSCODE
            AND pvb.PV_COMBI_ID    = pvm.PV_COMBI_ID
            AND pvb.SET_ID         = pvm.SET_ID
            AND pvm.PARAMETER_ID   = mkt.PARAMETER_ID
        ORDER BY pvb.SET_ID, pvm.PARAMETER_ID";

try {
    $conn = getConnection();

    $stmt = oci_parse($conn, $sql);
    if (!$stmt) {
        $err = oci_error($conn);
        throw new Exception('Erreur parse SQL : ' . $err['message']);
    }

    oci_bind_by_name($stmt, ':val', $bindValue, -1, $bindType);

    if (!oci_execute($stmt, OCI_NO_AUTO_COMMIT)) {
        $err = oci_error($stmt);
        throw new Exception('Erreur execution SQL : ' . $err['message']);
    }

    $offre    = null;
    $pricings = [];   // indexé par SET_ID pendant la construction

    while ($row = oci_fetch_assoc($stmt)) {

        // Initialisation des champs de l'offre à la première ligne
        if ($offre === null) {
            $offre = [
                'tmcode' => (int) $row['TMCODE'],
                'des'    => $row['DES'],
                'shdes'  => $row['SHDES'],
            ];
        }

        $setId = (int) $row['SET_ID'];

        // Initialisation du pricing si nouveau SET_ID
        if (!isset($pricings[$setId])) {
            $pricings[$setId] = [
                'set_id'     => $setId,
                'des'        => $row['PVB_DES'],
                'parametres' => [],
            ];
        }

        // Construction du paramètre
        $parametre = [
            'prm_des' => $row['PRM_DES'],
            'valeur'  => $row['PRM_VALUE_DES'],
        ];

        // L'accessfee est affiché uniquement sur le premier paramètre (01_Formule)
        if (empty($pricings[$setId]['parametres'])) {
            $parametre['accessfee'] = (float) $row['ACCESSFEE'];
        }

        $pricings[$setId]['parametres'][] = $parametre;
    }

    oci_free_statement($stmt);
    oci_close($conn);

    if ($offre === null) {
        sendResponse(404, ['success' => false, 'error' => 'Aucune offre trouvee pour ce critere de recherche']);
    }

    // Réindexation numérique du tableau pricings
    $offre['pricings'] = array_values($pricings);

    sendResponse(200, ['success' => true, 'offre' => $offre]);

} catch (Exception $e) {
    if (isset($conn) && $conn) {
        oci_close($conn);
    }
    sendResponse(500, ['success' => false, 'error' => $e->getMessage()]);
}
