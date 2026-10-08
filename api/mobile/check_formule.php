<?php
/**
 * GET /mobile/check_formule.php
 *
 * Vérifie si une formule existe déjà dans la table MPUSPTAB.
 *
 * Paramètres GET (au moins un requis) :
 *   des   — nom de la formule
 *   shdes — description courte (5 caractères, format D_***)
 *
 * Réponse :
 *   {
 *     "success": true,
 *     "checks": {
 *       "des":   { "value": "BR MIX 3", "exists": false },
 *       "shdes": { "value": "D_M11",    "exists": true  }
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

$des   = isset($_GET['des'])   ? trim($_GET['des'])            : null;
$shdes = isset($_GET['shdes']) ? strtoupper(trim($_GET['shdes'])) : null;

if ($des === null && $shdes === null) {
    sendResponse(400, ['success' => false, 'error' => 'Au moins un parametre est requis : des ou shdes']);
}

try {
    $conn   = getConnection();
    $checks = [];

    if ($des !== null) {
        $stmt = oci_parse($conn, 'SELECT COUNT(*) FROM MPUSPTAB WHERE DES = :val');
        if (!$stmt) {
            $err = oci_error($conn);
            throw new Exception('Erreur parse SQL : ' . $err['message']);
        }
        oci_bind_by_name($stmt, ':val', $des);
        oci_execute($stmt, OCI_NO_AUTO_COMMIT);
        $row = oci_fetch_array($stmt, OCI_NUM);
        oci_free_statement($stmt);
        $checks['des'] = ['value' => $des, 'exists' => ((int) $row[0] > 0)];
    }

    if ($shdes !== null) {
        $stmt = oci_parse($conn, 'SELECT COUNT(*) FROM MPUSPTAB WHERE SHDES = :val');
        if (!$stmt) {
            $err = oci_error($conn);
            throw new Exception('Erreur parse SQL : ' . $err['message']);
        }
        oci_bind_by_name($stmt, ':val', $shdes);
        oci_execute($stmt, OCI_NO_AUTO_COMMIT);
        $row = oci_fetch_array($stmt, OCI_NUM);
        oci_free_statement($stmt);
        $checks['shdes'] = ['value' => $shdes, 'exists' => ((int) $row[0] > 0)];
    }

    oci_close($conn);

    sendResponse(200, ['success' => true, 'checks' => $checks]);

} catch (Exception $e) {
    if (isset($conn) && $conn) {
        oci_close($conn);
    }
    sendResponse(500, ['success' => false, 'error' => $e->getMessage()]);
}
