<?php
/**
 * GET /mobile/get_offre.php
 *
 * Retourne les informations d'un plan tarifaire mobile avec ses formules
 * et le prix de chacune (ACCESSFEE de MPULKTM1 sur SNCODE 191).
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
 *       "tmcode": 861,
 *       "des":    "Mobile Confort",
 *       "shdes":  "MOBC",
 *       "des_fr": "B2C",
 *       "formules": [
 *         {
 *           "spcode":    1454,
 *           "des":       "BR MIX 3",
 *           "shdes":     "D_M11",
 *           "accessfee": 2119,
 *           "status":    "W",
 *           "vscode":    0
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

$provided = array_filter([$tmcode, $des, $shdes], function ($v) { return $v !== null && $v !== ''; });
if (count($provided) === 0) {
    sendResponse(400, ['success' => false, 'error' => 'Un parametre de recherche est requis : tmcode, des ou shdes']);
}
if (count($provided) > 1) {
    sendResponse(400, ['success' => false, 'error' => 'Fournissez un seul parametre a la fois : tmcode, des ou shdes']);
}

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

$sql = "SELECT
            ra.TMCODE,
            ra.DES      AS RA_DES,
            ra.SHDES    AS RA_SHDES,
            ra.DES_FR,
            sp.SPCODE,
            sp.DES      AS SP_DES,
            sp.SHDES    AS SP_SHDES,
            tm1.ACCESSFEE,
            tm1.STATUS,
            tm1.VSCODE
        FROM
            RATEPLAN               ra,
            TARIFF_SERVICE_PACKAGE tsp,
            MPUSPTAB               sp,
            MPULKTM1               tm1
        WHERE
            " . $whereClause . "
            AND tsp.TMCODE   = ra.TMCODE
            AND tsp.TMVSCODE = 0
            AND sp.SPCODE    = tsp.SPCODE
            AND tm1.TMCODE   = ra.TMCODE
            AND tm1.SPCODE   = sp.SPCODE
            AND tm1.SNCODE   = 191
            AND tm1.VSCODE   = (
                SELECT MAX(t2.VSCODE)
                FROM MPULKTM1 t2
                WHERE t2.TMCODE = tm1.TMCODE
                  AND t2.SPCODE = tm1.SPCODE
                  AND t2.SNCODE = 191
            )
        ORDER BY sp.SPCODE";

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
    $formules = [];

    while ($row = oci_fetch_assoc($stmt)) {
        if ($offre === null) {
            $offre = [
                'tmcode' => (int) $row['TMCODE'],
                'des'    => $row['RA_DES'],
                'shdes'  => $row['RA_SHDES'],
                'des_fr' => $row['DES_FR'],
            ];
        }

        $formules[] = [
            'spcode'    => (int)   $row['SPCODE'],
            'des'       => $row['SP_DES'],
            'shdes'     => $row['SP_SHDES'],
            'accessfee' => (float) $row['ACCESSFEE'],
            'status'    => $row['STATUS'],
            'vscode'    => (int)   $row['VSCODE'],
        ];
    }

    oci_free_statement($stmt);
    oci_close($conn);

    if ($offre === null) {
        sendResponse(404, ['success' => false, 'error' => 'Aucun plan tarifaire trouve pour ce critere de recherche']);
    }

    $offre['formules'] = $formules;

    sendResponse(200, ['success' => true, 'offre' => $offre]);

} catch (Exception $e) {
    if (isset($conn) && $conn) {
        oci_close($conn);
    }
    sendResponse(500, ['success' => false, 'error' => $e->getMessage()]);
}
