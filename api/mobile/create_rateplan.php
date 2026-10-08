<?php
/**
 * POST /mobile/create_rateplan.php
 *
 * Crée un nouveau plan tarifaire mobile dans RATEPLAN.
 * Le TMCODE est généré automatiquement via MAX(TMCODE)+1.
 *
 * Différence avec FTTH : deux lignes sont insérées dans RATEPLAN_VERSION —
 *   VSCODE 0 / STATUS 'W' puis VSCODE 1 / STATUS 'P'.
 *
 * Corps JSON requis :
 *   des                (string) — description longue (ex: "Mobile Confort")
 *   shdes              (string) — description courte unique (ex: "MOBC")
 *   des_fr             (string) — segment marché (ex: "B2C", "B2B", "TEST")
 *   vsdate             (string) — date de version dd-mm-yyyy (ex: "01-09-2025")
 *   apdate             (string) — date d'application dd-mm-yyyy hh:mm:ss (ex: "18-09-2025 10:24:32")
 *   extrnl_rateplan_id (int)    — identifiant externe (ex: 1=postpaid, 2=prepaid)
 *
 * Corps JSON optionnel :
 *   payment_method_ind   (string) — défaut : "P"
 *   charging_engine_code (int)    — défaut : 1
 *   add_options          (int)    — 0 (défaut) : rateplan seul ; 1 : insère les options par défaut
 *                                   (48 TSP + 90 TM1 + 29 TM2) dans la même transaction
 *
 * Réponse :
 *   {
 *     "success": true,
 *     "tmcode": 1285,
 *     "message": "Plan tarifaire cree avec succes"
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
requireFields($body, ['des', 'shdes', 'des_fr', 'vsdate', 'apdate', 'extrnl_rateplan_id']);

$des                = trim($body['des']);
$shdes              = trim($body['shdes']);
$desFr              = trim($body['des_fr']);
$vsdate             = trim($body['vsdate']);
$apdate             = trim($body['apdate']);
$extrnlRateplanId   = (int) $body['extrnl_rateplan_id'];
$paymentMethodInd   = isset($body['payment_method_ind'])   ? trim($body['payment_method_ind'])   : 'P';
$chargingEngineCode = isset($body['charging_engine_code']) ? (int) $body['charging_engine_code'] : 1;
$addOptions         = isset($body['add_options'])          ? (int) $body['add_options']          : 0;

if (!in_array($addOptions, [0, 1])) {
    sendResponse(400, ['success' => false, 'error' => 'add_options doit etre 0 ou 1']);
}

if (!preg_match('/^\d{2}-\d{2}-\d{4}$/', $vsdate)) {
    sendResponse(400, ['success' => false, 'error' => 'Format vsdate invalide. Utilisez dd-mm-yyyy (ex: 01-09-2025)']);
}
if (!preg_match('/^\d{2}-\d{2}-\d{4} \d{2}:\d{2}:\d{2}$/', $apdate)) {
    sendResponse(400, ['success' => false, 'error' => 'Format apdate invalide. Utilisez dd-mm-yyyy hh:mm:ss (ex: 18-09-2025 10:24:32)']);
}

try {
    $conn = getConnection();

    // Vérification des doublons avant insertion
    $stmtChk = oci_parse($conn, 'SELECT COUNT(*) FROM RATEPLAN WHERE SHDES = :shdes');
    oci_bind_by_name($stmtChk, ':shdes', $shdes);
    oci_execute($stmtChk, OCI_NO_AUTO_COMMIT);
    $rowChk = oci_fetch_array($stmtChk, OCI_NUM);
    oci_free_statement($stmtChk);

    if ((int) $rowChk[0] > 0) {
        oci_close($conn);
        sendResponse(409, ['success' => false, 'error' => 'Un plan tarifaire avec ce SHDES existe deja : ' . $shdes]);
    }

    // Lecture du prochain TMCODE dans la table de séquences
    $stmtSeq = oci_parse($conn, 'SELECT next_free_value FROM sysadm.app_sequence_value WHERE app_sequence_id = 68');
    if (!$stmtSeq) {
        $err = oci_error($conn);
        throw new Exception('Erreur parse sequence TMCODE : ' . $err['message']);
    }
    if (!oci_execute($stmtSeq, OCI_NO_AUTO_COMMIT)) {
        $err = oci_error($stmtSeq);
        oci_free_statement($stmtSeq);
        throw new Exception('Erreur lecture sequence TMCODE : ' . $err['message']);
    }
    $rowSeq = oci_fetch_array($stmtSeq, OCI_NUM);
    oci_free_statement($stmtSeq);
    $tmcode = (int) $rowSeq[0];

    // ── RATEPLAN ─────────────────────────────────────────────────────────────
    $binds = [
        ':tmcode'               => $tmcode,
        ':des'                  => $des,
        ':shdes'                => $shdes,
        ':des_fr'               => $desFr,
        ':extrnl_rateplan_id'   => $extrnlRateplanId,
        ':charging_engine_code' => $chargingEngineCode,
        ':payment_method_ind'   => $paymentMethodInd,
    ];

    executeInsert($conn, "INSERT INTO RATEPLAN (
            TMCODE, DES, SHDES, TMGLOBAL, REC_VERSION, PRERATED_TAP_RP_IND,
            EXTRNL_RATEPLAN_ID, PDE_RATEPLAN, PROVISION_EXT, DES_FR,
            IMPORT_EXPORT_FLAG, SPECIAL_USAGE_IND, SERVICE_CLASS_ID,
            CHARGING_ENGINE_CODE, PAYMENT_METHOD_IND, BUSINESS_LINE_ID
        ) VALUES (
            :tmcode, :des, :shdes, 'N', 1, 'N',
            :extrnl_rateplan_id, NULL, NULL, :des_fr,
            2, NULL, NULL,
            :charging_engine_code, :payment_method_ind, NULL
        )", $binds);

    // ── RATEPLAN_VERSION — 1 ligne : VSCODE 0 / STATUS 'W' ──────────────────
    $stmtRpv = oci_parse($conn, "INSERT INTO RATEPLAN_VERSION (
                   VSCODE, VSDATE, STATUS, TMRC, APDATE, CURRENCY, REC_VERSION,
                   TMCODE, PLCODE, IMPORT_EXPORT_FLAG,
                   INT_SYNC, INVALID_IND, INVALIDATED_VSDATE, INVALIDATED_VSCODE,
                   BACKDATE_IND, CORE_SERVICE_FLAG
               ) VALUES (
                   0,
                   TO_DATE(:vsdate, 'DD-MM-YYYY'),
                   'W',
                   0,
                   TO_DATE(:apdate, 'DD-MM-YYYY HH24:MI:SS'),
                   72, 1, :tmcode,
                   NULL, 2,
                   NULL, NULL, NULL, NULL, NULL, NULL
               )");
    if (!$stmtRpv) {
        $err = oci_error($conn);
        throw new Exception('Erreur parse RATEPLAN_VERSION : ' . $err['message']);
    }
    oci_bind_by_name($stmtRpv, ':vsdate', $vsdate);
    oci_bind_by_name($stmtRpv, ':apdate', $apdate);
    oci_bind_by_name($stmtRpv, ':tmcode', $tmcode, -1, SQLT_INT);

    if (!oci_execute($stmtRpv, OCI_NO_AUTO_COMMIT)) {
        $err = oci_error($stmtRpv);
        oci_free_statement($stmtRpv);
        throw new Exception('Erreur INSERT RATEPLAN_VERSION : ' . $err['message']);
    }
    oci_free_statement($stmtRpv);

    // ── OPTIONS PAR DÉFAUT (add_options=1) ───────────────────────────────────
    if ($addOptions === 1) {
        require_once __DIR__ . '/options_data.php';
        $optData = getMobileOptionsData();

        foreach ($optData['tsp'] as $sql) {
            $stmt = oci_parse($conn, $sql);
            if (!$stmt) {
                $err = oci_error($conn);
                throw new Exception('Erreur parse TSP option : ' . $err['message']);
            }
            oci_bind_by_name($stmt, ':tmcode', $tmcode, -1, SQLT_INT);
            if (!oci_execute($stmt, OCI_NO_AUTO_COMMIT)) {
                $err = oci_error($stmt);
                oci_free_statement($stmt);
                throw new Exception('Erreur INSERT TSP option : ' . $err['message']);
            }
            oci_free_statement($stmt);
        }

        foreach ($optData['tm1'] as $sql) {
            $stmt = oci_parse($conn, $sql);
            if (!$stmt) {
                $err = oci_error($conn);
                throw new Exception('Erreur parse TM1 option : ' . $err['message']);
            }
            oci_bind_by_name($stmt, ':tmcode', $tmcode, -1, SQLT_INT);
            oci_bind_by_name($stmt, ':vsdate', $vsdate);
            if (!oci_execute($stmt, OCI_NO_AUTO_COMMIT)) {
                $err = oci_error($stmt);
                oci_free_statement($stmt);
                throw new Exception('Erreur INSERT TM1 option : ' . $err['message']);
            }
            oci_free_statement($stmt);
        }

        foreach ($optData['tm2'] as $sql) {
            $stmt = oci_parse($conn, $sql);
            if (!$stmt) {
                $err = oci_error($conn);
                throw new Exception('Erreur parse TM2 option : ' . $err['message']);
            }
            oci_bind_by_name($stmt, ':tmcode', $tmcode, -1, SQLT_INT);
            oci_bind_by_name($stmt, ':vsdate', $vsdate);
            if (!oci_execute($stmt, OCI_NO_AUTO_COMMIT)) {
                $err = oci_error($stmt);
                oci_free_statement($stmt);
                throw new Exception('Erreur INSERT TM2 option : ' . $err['message']);
            }
            oci_free_statement($stmt);
        }
    }

    // Mise à jour de la séquence TMCODE
    $nextTmcode = $tmcode + 1;
    $stmtUpd = oci_parse($conn, 'UPDATE sysadm.app_sequence_value SET next_free_value = :next WHERE app_sequence_id = 68');
    if (!$stmtUpd) {
        $err = oci_error($conn);
        throw new Exception('Erreur parse update sequence TMCODE : ' . $err['message']);
    }
    oci_bind_by_name($stmtUpd, ':next', $nextTmcode, -1, SQLT_INT);
    if (!oci_execute($stmtUpd, OCI_NO_AUTO_COMMIT)) {
        $err = oci_error($stmtUpd);
        oci_free_statement($stmtUpd);
        throw new Exception('Erreur update sequence TMCODE : ' . $err['message']);
    }
    oci_free_statement($stmtUpd);

    oci_commit($conn);
    oci_close($conn);

    sendResponse(201, [
        'success' => true,
        'tmcode'  => $tmcode,
        'message' => 'Plan tarifaire cree avec succes',
    ]);

} catch (Exception $e) {
    if (isset($conn) && $conn) {
        oci_rollback($conn);
        oci_close($conn);
    }
    sendResponse(500, ['success' => false, 'error' => $e->getMessage()]);
}
