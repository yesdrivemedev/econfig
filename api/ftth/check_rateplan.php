<?php
/**
 * GET /check_rateplan.php
 *
 * Vérifie si un plan tarifaire existe déjà dans la table RATEPLAN.
 *
 * Paramètres GET (au moins un requis) :
 *   tmcode  — code du plan tarifaire
 *   des     — description longue
 *   shdes   — description courte (doit être unique)
 *
 * Réponse :
 *   {
 *     "success": true,
 *     "checks": {
 *       "tmcode": { "value": 1284, "exists": true },
 *       "des":    { "value": "FTTR Confort", "exists": false },
 *       "shdes":  { "value": "FTTRC", "exists": false }
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

if ($tmcode === null && $des === null && $shdes === null) {
    sendResponse(400, ['success' => false, 'error' => 'Au moins un parametre est requis : tmcode, des ou shdes']);
}

try {
    $conn   = getConnection();
    $checks = [];

    if ($tmcode !== null) {
        $stmt = oci_parse($conn, 'SELECT COUNT(*) FROM RATEPLAN WHERE TMCODE = :val');
        $val  = (int) $tmcode;
        oci_bind_by_name($stmt, ':val', $val);
        oci_execute($stmt, OCI_NO_AUTO_COMMIT);
        $row = oci_fetch_array($stmt, OCI_NUM);
        oci_free_statement($stmt);
        $checks['tmcode'] = ['value' => (int) $tmcode, 'exists' => ((int) $row[0] > 0)];
    }

    if ($des !== null) {
        $stmt = oci_parse($conn, 'SELECT COUNT(*) FROM RATEPLAN WHERE DES = :val');
        oci_bind_by_name($stmt, ':val', $des);
        oci_execute($stmt, OCI_NO_AUTO_COMMIT);
        $row = oci_fetch_array($stmt, OCI_NUM);
        oci_free_statement($stmt);
        $checks['des'] = ['value' => $des, 'exists' => ((int) $row[0] > 0)];
    }

    if ($shdes !== null) {
        $stmt = oci_parse($conn, 'SELECT COUNT(*) FROM RATEPLAN WHERE SHDES = :val');
        oci_bind_by_name($stmt, ':val', $shdes);
        oci_execute($stmt, OCI_NO_AUTO_COMMIT);
        $row = oci_fetch_array($stmt, OCI_NUM);
        oci_free_statement($stmt);
        $checks['shdes'] = ['value' => $shdes, 'exists' => ((int) $row[0] > 0)];
    }

    oci_close($conn);

    sendResponse(200, ['success' => true, 'checks' => $checks]);

} catch (Exception $e) {
    sendResponse(500, ['success' => false, 'error' => $e->getMessage()]);
}
