<?php
/**
 * POST /4g/create_rateplan.php
 *
 * Crée un nouveau plan tarifaire 4G dans RATEPLAN.
 * Le TMCODE est lu dans sysadm.app_sequence_value (app_sequence_id=68) et la séquence est incrémentée après insertion.
 *
 * Corps JSON requis :
 *   des                (string) — description longue (ex: "4G Office Hyb 500Go")
 *   shdes              (string) — description courte unique (ex: "4OfG5")
 *   des_fr             (string) — segment marché (ex: "B2C", "B2B", "TEST")
 *   vsdate             (string) — date de version dd-mm-yyyy (ex: "19-03-2024")
 *   apdate             (string) — date d'application dd-mm-yyyy hh:mm:ss (ex: "18-03-2024 20:20:22")
 *   extrnl_rateplan_id (int)    — identifiant externe (ex: 1=postpaid, 2=prepaid)
 *
 * Corps JSON optionnel :
 *   payment_method_ind   (string) — défaut : "P"
 *   charging_engine_code (int)    — défaut : 1
 *   add_options          (int)    — 0 (défaut) : rateplan seul
 *                                   1 : insère aussi les options par défaut
 *                                       (2 TSP + 2 MPULKTM1) dans la même transaction
 *   easybox              (int)    — 0 (défaut) : sans option Easybox
 *                                   1 : insère TARIFF_SERVICE_PACKAGE + MPULKTM1
 *                                       pour SPCODE=1373 (SNCODE=944) dans la même transaction
 *
 * Réponse :
 *   {
 *     "success": true,
 *     "tmcode": 1256,
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
$easybox            = isset($body['easybox'])              ? (int) $body['easybox']              : 0;

if (!in_array($addOptions, [0, 1])) {
    sendResponse(400, ['success' => false, 'error' => 'add_options doit etre 0 ou 1']);
}
if (!in_array($easybox, [0, 1])) {
    sendResponse(400, ['success' => false, 'error' => 'easybox doit etre 0 ou 1']);
}
if (!preg_match('/^\d{2}-\d{2}-\d{4}$/', $vsdate)) {
    sendResponse(400, ['success' => false, 'error' => 'Format vsdate invalide. Utilisez dd-mm-yyyy (ex: 19-03-2024)']);
}
if (!preg_match('/^\d{2}-\d{2}-\d{4} \d{2}:\d{2}:\d{2}$/', $apdate)) {
    sendResponse(400, ['success' => false, 'error' => 'Format apdate invalide. Utilisez dd-mm-yyyy hh:mm:ss (ex: 18-03-2024 20:20:22)']);
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

    // ── RATEPLAN_VERSION — VSCODE 0 / STATUS 'W' ─────────────────────────────
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

        // ── TARIFF_SERVICE_PACKAGE — 2 lignes ─────────────────────────────────
        $tspRows = [
            ['spcode' => 999,  'atomic' => null],
            ['spcode' => 1645, 'atomic' => 'X'],
        ];

        $sqlTsp = "INSERT INTO TARIFF_SERVICE_PACKAGE (
                       TMCODE, SPCODE, TMVSCODE, SERVICE_PACKAGE_DEF, ATOMIC_SERVICE_PACKAGE
                   ) VALUES (
                       :tmcode, :spcode, 0, NULL, :atomic
                   )";

        foreach ($tspRows as $tsp) {
            $stmtTsp = oci_parse($conn, $sqlTsp);
            if (!$stmtTsp) {
                $err = oci_error($conn);
                throw new Exception('Erreur parse TARIFF_SERVICE_PACKAGE : ' . $err['message']);
            }
            oci_bind_by_name($stmtTsp, ':tmcode', $tmcode,       -1, SQLT_INT);
            oci_bind_by_name($stmtTsp, ':spcode', $tsp['spcode'], -1, SQLT_INT);
            oci_bind_by_name($stmtTsp, ':atomic', $tsp['atomic'], -1, SQLT_CHR);
            if (!oci_execute($stmtTsp, OCI_NO_AUTO_COMMIT)) {
                $err = oci_error($stmtTsp);
                oci_free_statement($stmtTsp);
                throw new Exception('Erreur INSERT TARIFF_SERVICE_PACKAGE (SPCODE ' . $tsp['spcode'] . ') : ' . $err['message']);
            }
            oci_free_statement($stmtTsp);
        }

        // ── MPULKTM1 — 2 lignes ───────────────────────────────────────────────
        // Champs fixes : SUBSCRIPT=0, ACCESSFEE=0, ECHIND/AMTIND/FRQIND=null,
        //   SRVIND='P', ADVIND='P', SUSIND='N', ACCSERV='Ca1 20%'/'Cd1 20%',
        //   SUBSERV='Ca1 20%'/'Cd1 20%', INTERVAL_TYPE='M', INTERVAL=1,
        //   DEF_PAYMENT_COND_USG=3, INITIAL_CREDIT=0, DEF_PAYMENT_COND_ONETIME_REC=1,
        //   OVW_PERIOD_TYPE='M', SUB_GL_ACC_PACK_ID=1, ACC_GL_ACC_PACK_ID=1
        // Champs variants : SPCODE, SNCODE, PROIND, CSIND, CLCODE, SERVICE_PRIORITY
        $tm1Rows = [
            ['spcode' => 999,  'sncode' => 767,  'proind' => 'N', 'csind' => 'X',  'clcode' => 2,    'service_priority' => 767],
            ['spcode' => 1645, 'sncode' => 1052, 'proind' => 'Y', 'csind' => null, 'clcode' => null, 'service_priority' => 0],
        ];

        $sqlTm1 = "INSERT INTO MPULKTM1 (
                       TMCODE, VSCODE, VSDATE, STATUS, SPCODE, SNCODE,
                       SUBSCRIPT, ACCESSFEE, EVENT, ECHIND, AMTIND, FRQIND,
                       SRVIND, PROIND, ADVIND, SUSIND,
                       LTCODE, PLCODE, BILLFREQ, FREEDAYS,
                       CSIND, CLCODE,
                       ACCSERV_CATCODE, ACCSERV_CODE, ACCSERV_TYPE,
                       USGSERV_CATCODE, USGSERV_CODE, USGSERV_TYPE,
                       SUBSERV_CATCODE, SUBSERV_CODE, SUBSERV_TYPE,
                       DEPOSIT, INTERVAL_TYPE, INTERVAL,
                       PV_COMBI_ID, PRM_PRINT_IND, PRINTSUBSCRIND, PRINTACCESSIND,
                       REC_VERSION, NON_EXPL_SERV_PREPAID_IND,
                       DEF_PAYMENT_COND_USG, DEF_TIME_PACKAGE_USG, INITIAL_CREDIT,
                       PAYMENT_COND_CHANGEABLE_USG, BOP_MODE_ID,
                       DEF_PAYMENT_COND_ONETIME_REC,
                       OVW_RECUR_AMOUNT, OVW_PERIOD_TYPE, OVW_PERIOD_LENGTH,
                       SERVICE_PRIORITY, CHARGING_SCHEDULE, ACCOUNT_ID,
                       DEF_TYPE_OF_CONTROL,
                       SUB_GL_ACC_PACK_ID, ACC_GL_ACC_PACK_ID, USG_GL_ACC_PACK_ID,
                       GL_PACK_SYSTEM_SCENARIO_FLAG,
                       OFFER_ID, PAM_CONFIG_ID_MAIN, PAM_CONFIG_ID_REC_CHARGE
                   ) VALUES (
                       :tmcode, 0, TO_DATE(:vsdate, 'DD-MM-YYYY'), 'W', :spcode, :sncode,
                       0, 0, NULL, NULL, NULL, NULL,
                       'P', :proind, 'P', 'N',
                       NULL, NULL, NULL, NULL,
                       :csind, :clcode,
                       'Ca1 20%', 'Cd1 20%', 'ALL',
                       NULL, NULL, NULL,
                       'Ca1 20%', 'Cd1 20%', 'ALL',
                       NULL, 'M', 1,
                       NULL, 'N', 'N', 'N',
                       1, NULL,
                       3, NULL, 0,
                       NULL, NULL,
                       1,
                       NULL, 'M', NULL,
                       :service_priority, 'B', NULL,
                       NULL,
                       1, 1, NULL,
                       NULL,
                       NULL, NULL, NULL
                   )";

        foreach ($tm1Rows as $row) {
            $stmt = oci_parse($conn, $sqlTm1);
            if (!$stmt) {
                $err = oci_error($conn);
                throw new Exception('Erreur parse MPULKTM1 : ' . $err['message']);
            }
            oci_bind_by_name($stmt, ':tmcode',           $tmcode,               -1, SQLT_INT);
            oci_bind_by_name($stmt, ':vsdate',           $vsdate);
            oci_bind_by_name($stmt, ':spcode',           $row['spcode'],         -1, SQLT_INT);
            oci_bind_by_name($stmt, ':sncode',           $row['sncode'],         -1, SQLT_INT);
            oci_bind_by_name($stmt, ':proind',           $row['proind']);
            oci_bind_by_name($stmt, ':csind',            $row['csind'],          -1, SQLT_CHR);
            oci_bind_by_name($stmt, ':clcode',           $row['clcode'],         -1, ociType($row['clcode']));
            oci_bind_by_name($stmt, ':service_priority', $row['service_priority'], -1, SQLT_INT);
            if (!oci_execute($stmt, OCI_NO_AUTO_COMMIT)) {
                $err = oci_error($stmt);
                oci_free_statement($stmt);
                throw new Exception('Erreur INSERT MPULKTM1 (SPCODE ' . $row['spcode'] . ') : ' . $err['message']);
            }
            oci_free_statement($stmt);
        }
    }

    // ── OPTION EASYBOX (easybox=1) ───────────────────────────────────────────
    if ($easybox === 1) {

        $stmtEbTsp = oci_parse($conn,
            "INSERT INTO TARIFF_SERVICE_PACKAGE
                 (TMCODE, SPCODE, TMVSCODE, SERVICE_PACKAGE_DEF, ATOMIC_SERVICE_PACKAGE)
             VALUES (:tmcode, 1373, 0, NULL, 'X')");
        if (!$stmtEbTsp) {
            $err = oci_error($conn);
            throw new Exception('Erreur parse TARIFF_SERVICE_PACKAGE easybox : ' . $err['message']);
        }
        oci_bind_by_name($stmtEbTsp, ':tmcode', $tmcode, -1, SQLT_INT);
        if (!oci_execute($stmtEbTsp, OCI_NO_AUTO_COMMIT)) {
            $err = oci_error($stmtEbTsp);
            oci_free_statement($stmtEbTsp);
            throw new Exception('Erreur INSERT TARIFF_SERVICE_PACKAGE easybox : ' . $err['message']);
        }
        oci_free_statement($stmtEbTsp);

        $stmtEbTm1 = oci_parse($conn,
            "INSERT INTO MPULKTM1 (
                 TMCODE, VSCODE, VSDATE, STATUS, SPCODE, SNCODE,
                 SUBSCRIPT, ACCESSFEE, EVENT, ECHIND, AMTIND, FRQIND,
                 SRVIND, PROIND, ADVIND, SUSIND,
                 LTCODE, PLCODE, BILLFREQ, FREEDAYS, CSIND, CLCODE,
                 ACCSERV_CATCODE, ACCSERV_CODE, ACCSERV_TYPE,
                 USGSERV_CATCODE, USGSERV_CODE, USGSERV_TYPE,
                 SUBSERV_CATCODE, SUBSERV_CODE, SUBSERV_TYPE,
                 DEPOSIT, INTERVAL_TYPE, INTERVAL,
                 PV_COMBI_ID, PRM_PRINT_IND, PRINTSUBSCRIND, PRINTACCESSIND,
                 REC_VERSION, NON_EXPL_SERV_PREPAID_IND,
                 DEF_PAYMENT_COND_USG, DEF_TIME_PACKAGE_USG, INITIAL_CREDIT,
                 PAYMENT_COND_CHANGEABLE_USG, BOP_MODE_ID,
                 DEF_PAYMENT_COND_ONETIME_REC,
                 OVW_RECUR_AMOUNT, OVW_PERIOD_TYPE, OVW_PERIOD_LENGTH,
                 SERVICE_PRIORITY, CHARGING_SCHEDULE, ACCOUNT_ID,
                 DEF_TYPE_OF_CONTROL,
                 SUB_GL_ACC_PACK_ID, ACC_GL_ACC_PACK_ID, USG_GL_ACC_PACK_ID,
                 GL_PACK_SYSTEM_SCENARIO_FLAG,
                 OFFER_ID, PAM_CONFIG_ID_MAIN, PAM_CONFIG_ID_REC_CHARGE
             ) VALUES (
                 :tmcode, 0, TO_DATE(:vsdate, 'DD-MM-YYYY'), 'W', 1373, 944,
                 0, 0, NULL, NULL, NULL, NULL,
                 'P', 'N', 'P', 'N',
                 NULL, NULL, NULL, NULL, NULL, NULL,
                 'Ca1 20%', 'Cd1 20%', 'ALL',
                 NULL, NULL, NULL,
                 'Ca1 20%', 'Cd1 20%', 'ALL',
                 NULL, 'M', 1,
                 NULL, 'N', 'N', 'N',
                 1, NULL,
                 3, NULL, 0,
                 NULL, NULL,
                 1,
                 NULL, 'M', NULL,
                 944, 'B', NULL,
                 NULL,
                 1, 1, NULL,
                 NULL,
                 NULL, NULL, NULL
             )");
        if (!$stmtEbTm1) {
            $err = oci_error($conn);
            throw new Exception('Erreur parse MPULKTM1 easybox : ' . $err['message']);
        }
        oci_bind_by_name($stmtEbTm1, ':tmcode', $tmcode, -1, SQLT_INT);
        oci_bind_by_name($stmtEbTm1, ':vsdate', $vsdate);
        if (!oci_execute($stmtEbTm1, OCI_NO_AUTO_COMMIT)) {
            $err = oci_error($stmtEbTm1);
            oci_free_statement($stmtEbTm1);
            throw new Exception('Erreur INSERT MPULKTM1 easybox : ' . $err['message']);
        }
        oci_free_statement($stmtEbTm1);
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
