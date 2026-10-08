<?php
/**
 * POST /mobile/create_formule.php
 *
 * Crée une ou plusieurs formules (service packages) et les rattache à un plan tarifaire.
 *
 * Pour chaque formule :
 *   1. INSERT MPUSPTAB       — création de la formule (SPCODE lu dans app_sequence_value, app_sequence_id=122)
 *   2. INSERT MPULKPXN × 15 — ajout des services fixes (SNCODEs invariables)
 *   3. INSERT TARIFF_SERVICE_PACKAGE × 2 — rattachement au rateplan (TMVSCODE 0 et 1)
 *
 * SNCODEs fixes insérés dans MPULKPXN : 1,2,3,4,5,6,8,9,10,11,50,52,53,61,191
 *
 * Corps JSON requis :
 *   tmcode   (int)   — plan tarifaire auquel rattacher les formules
 *   formules (array) — liste des formules (1 à 20) :
 *     [
 *       { "des": "BR MIX 3", "shdes": "D_M11" },
 *       { "des": "BR MIX 6", "shdes": "D_M12" },
 *       ...
 *     ]
 *     des   (string) — nom de la formule, unique
 *     shdes (string) — description courte, 5 caractères, format D_*** (ex: "D_M11")
 *
 * Réponse :
 *   {
 *     "success": true,
 *     "tmcode": 861,
 *     "formules": [
 *       { "spcode": 1454, "des": "BR MIX 3", "shdes": "D_M11" },
 *       { "spcode": 1455, "des": "BR MIX 6", "shdes": "D_M12" }
 *     ],
 *     "message": "2 formule(s) creee(s) avec succes"
 *   }
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/response.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(405, ['success' => false, 'error' => 'Methode non autorisee. Utilisez POST.']);
}

authenticate();

$body = getRequestBody();
requireFields($body, ['tmcode', 'formules']);

$tmcode   = (int) $body['tmcode'];
$formules = $body['formules'];

if (!is_array($formules) || empty($formules)) {
    sendResponse(400, ['success' => false, 'error' => 'Le champ "formules" doit etre un tableau non vide']);
}
if (count($formules) > 20) {
    sendResponse(400, ['success' => false, 'error' => 'Maximum 20 formules par appel']);
}

// Validation de chaque formule avant toute insertion
foreach ($formules as $i => $f) {
    if (!isset($f['des']) || trim($f['des']) === '') {
        sendResponse(400, ['success' => false, 'error' => 'Champ "des" manquant dans formules[' . $i . ']']);
    }
    if (!isset($f['shdes']) || trim($f['shdes']) === '') {
        sendResponse(400, ['success' => false, 'error' => 'Champ "shdes" manquant dans formules[' . $i . ']']);
    }
    if (!preg_match('/^D_[A-Z0-9]{3}$/', strtoupper(trim($f['shdes'])))) {
        sendResponse(400, ['success' => false,
            'error' => 'Format shdes invalide dans formules[' . $i . '] : doit etre 5 caracteres sous la forme D_*** (ex: D_M11)']);
    }
}

// SNCODEs fixes pour MPULKPXN
$sncodes = [1, 2, 3, 4, 5, 6, 8, 9, 10, 11, 50, 52, 53, 61, 191];

$sqlMpusptab = "INSERT INTO MPUSPTAB (
                    SPCODE, DES, SHDES, REC_VERSION,
                    PDE_SERVICE_PACKAGE, DES_FR, IMPORT_EXPORT_FLAG,
                    SPECIAL_USAGE_IND, CHARGING_ENGINE_CODE, ATOMIC_SERVICE_PACKAGE
                ) VALUES (
                    :spcode, :des, :shdes, 1,
                    NULL, NULL, 0,
                    NULL, 1, NULL
                )";

$sqlPxn = "INSERT INTO MPULKPXN (SPCODE, SNCODE, REC_VERSION)
           VALUES (:spcode, :sncode, 1)";

$sqlTsp = "INSERT INTO TARIFF_SERVICE_PACKAGE (
               TMCODE, SPCODE, TMVSCODE, SERVICE_PACKAGE_DEF, ATOMIC_SERVICE_PACKAGE
           ) VALUES (
               :tmcode, :spcode, :tmvscode, NULL, NULL
           )";

try {
    $conn = getConnection();

    // Vérification de doublons sur shdes avant toute insertion
    foreach ($formules as $i => $f) {
        $shdes = strtoupper(trim($f['shdes']));
        $stmtChk = oci_parse($conn, 'SELECT COUNT(*) FROM MPUSPTAB WHERE SHDES = :shdes');
        if (!$stmtChk) {
            $err = oci_error($conn);
            throw new Exception('Erreur parse verification SHDES : ' . $err['message']);
        }
        oci_bind_by_name($stmtChk, ':shdes', $shdes);
        oci_execute($stmtChk, OCI_NO_AUTO_COMMIT);
        $rowChk = oci_fetch_array($stmtChk, OCI_NUM);
        oci_free_statement($stmtChk);

        if ((int) $rowChk[0] > 0) {
            oci_close($conn);
            sendResponse(409, ['success' => false,
                'error' => 'Une formule avec ce SHDES existe deja : ' . $shdes]);
        }
    }

    // Lecture du premier SPCODE disponible dans la table de séquences
    $stmtSeq = oci_parse($conn, 'SELECT next_free_value FROM sysadm.app_sequence_value WHERE app_sequence_id = 122');
    if (!$stmtSeq) {
        $err = oci_error($conn);
        throw new Exception('Erreur parse sequence SPCODE : ' . $err['message']);
    }
    if (!oci_execute($stmtSeq, OCI_NO_AUTO_COMMIT)) {
        $err = oci_error($stmtSeq);
        oci_free_statement($stmtSeq);
        throw new Exception('Erreur lecture sequence SPCODE : ' . $err['message']);
    }
    $rowSeq = oci_fetch_array($stmtSeq, OCI_NUM);
    oci_free_statement($stmtSeq);
    $firstSpcode = (int) $rowSeq[0];

    $result = [];

    foreach ($formules as $i => $f) {
        $des    = trim($f['des']);
        $shdes  = strtoupper(trim($f['shdes']));
        $spcode = $firstSpcode + $i;

        // ── MPUSPTAB ──────────────────────────────────────────────────────────
        $stmtSpt = oci_parse($conn, $sqlMpusptab);
        if (!$stmtSpt) {
            $err = oci_error($conn);
            throw new Exception('Erreur parse MPUSPTAB : ' . $err['message']);
        }
        oci_bind_by_name($stmtSpt, ':spcode', $spcode, -1, SQLT_INT);
        oci_bind_by_name($stmtSpt, ':des',    $des);
        oci_bind_by_name($stmtSpt, ':shdes',  $shdes);
        if (!oci_execute($stmtSpt, OCI_NO_AUTO_COMMIT)) {
            $err = oci_error($stmtSpt);
            oci_free_statement($stmtSpt);
            throw new Exception('Erreur INSERT MPUSPTAB (' . $shdes . ') : ' . $err['message']);
        }
        oci_free_statement($stmtSpt);

        // ── MPULKPXN — 15 SNCODEs fixes ───────────────────────────────────────
        foreach ($sncodes as $sncode) {
            $stmtPxn = oci_parse($conn, $sqlPxn);
            if (!$stmtPxn) {
                $err = oci_error($conn);
                throw new Exception('Erreur parse MPULKPXN : ' . $err['message']);
            }
            oci_bind_by_name($stmtPxn, ':spcode', $spcode, -1, SQLT_INT);
            oci_bind_by_name($stmtPxn, ':sncode', $sncode, -1, SQLT_INT);
            if (!oci_execute($stmtPxn, OCI_NO_AUTO_COMMIT)) {
                $err = oci_error($stmtPxn);
                oci_free_statement($stmtPxn);
                throw new Exception('Erreur INSERT MPULKPXN (SPCODE ' . $spcode
                    . ', SNCODE ' . $sncode . ') : ' . $err['message']);
            }
            oci_free_statement($stmtPxn);
        }

        // ── TARIFF_SERVICE_PACKAGE — TMVSCODE 0 ───────────────────────────────
        $tmvscode = 0;
        $stmtTsp = oci_parse($conn, $sqlTsp);
        if (!$stmtTsp) {
            $err = oci_error($conn);
            throw new Exception('Erreur parse TARIFF_SERVICE_PACKAGE : ' . $err['message']);
        }
        oci_bind_by_name($stmtTsp, ':tmcode',   $tmcode,   -1, SQLT_INT);
        oci_bind_by_name($stmtTsp, ':spcode',   $spcode,   -1, SQLT_INT);
        oci_bind_by_name($stmtTsp, ':tmvscode', $tmvscode, -1, SQLT_INT);
        if (!oci_execute($stmtTsp, OCI_NO_AUTO_COMMIT)) {
            $err = oci_error($stmtTsp);
            oci_free_statement($stmtTsp);
            throw new Exception('Erreur INSERT TARIFF_SERVICE_PACKAGE (SPCODE ' . $spcode . ') : ' . $err['message']);
        }
        oci_free_statement($stmtTsp);

        $result[] = ['spcode' => $spcode, 'des' => $des, 'shdes' => $shdes];
    }

    // Mise à jour de la séquence SPCODE
    $nextSpcode = $firstSpcode + count($formules);
    $stmtUpd = oci_parse($conn, 'UPDATE sysadm.app_sequence_value SET next_free_value = :next WHERE app_sequence_id = 122');
    if (!$stmtUpd) {
        $err = oci_error($conn);
        throw new Exception('Erreur parse update sequence SPCODE : ' . $err['message']);
    }
    oci_bind_by_name($stmtUpd, ':next', $nextSpcode, -1, SQLT_INT);
    if (!oci_execute($stmtUpd, OCI_NO_AUTO_COMMIT)) {
        $err = oci_error($stmtUpd);
        oci_free_statement($stmtUpd);
        throw new Exception('Erreur update sequence SPCODE : ' . $err['message']);
    }
    oci_free_statement($stmtUpd);

    oci_commit($conn);
    oci_close($conn);

    sendResponse(201, [
        'success'  => true,
        'tmcode'   => $tmcode,
        'formules' => $result,
        'message'  => count($result) . ' formule(s) creee(s) avec succes',
    ]);

} catch (Exception $e) {
    if (isset($conn) && $conn) {
        oci_rollback($conn);
        oci_close($conn);
    }
    sendResponse(500, ['success' => false, 'error' => $e->getMessage()]);
}
