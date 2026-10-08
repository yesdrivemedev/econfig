<?php
/**
 * POST /create_rateplan.php
 *
 * Crée un nouveau plan tarifaire dans la table RATEPLAN.
 * Le TMCODE est lu dans sysadm.app_sequence_value (app_sequence_id=68) et la séquence est incrémentée après insertion.
 *
 * Corps JSON requis :
 *   des    (string) — description longue (ex: "FTTR Confort")
 *   shdes  (string) — description courte unique (ex: "FTTRC")
 *   des_fr (string) — segment marché (ex: "B2C", "B2B", "TEST")
 *   vsdate             (string) — date de version format dd-mm-yyyy (ex: "01-09-2025")
 *   apdate             (string) — date d'application format dd-mm-yyyy hh:mm:ss (ex: "18-09-2025 10:24:32")
 *   extrnl_rateplan_id (int)    — identifiant externe libre (ex: 1=postpaid, 2=prepaid, ...)
 *
 * Corps JSON optionnel :
 *   payment_method_ind   (string) — défaut : "P"
 *   charging_engine_code (int)    — défaut : 1
 *   cesar                (int)    — défaut : 0. Si 1, associe la formule César (SPCODE=1532)
 *                                   au rateplan (TSP + MPULKTM1 + MPULKPV1 + MPULKPV2).
 *
 * Corps JSON requis si cesar=1 :
 *   cesar_des              (string) — libellé du pricing César (ex: "FTTH CESAR")
 *   cesar_accessfee        (float)  — montant de la recharge (ex: 33058)
 *   cesar_prm_value_string (string) — valeur du paramètre 479 (ex: "CESAR_VALUE")
 *
 * Réponse :
 *   {
 *     "success": true,
 *     "tmcode": 1285,
 *     "pv_combi_id": 2981,        ← présent uniquement si cesar=1
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

$des                  = trim($body['des']);
$shdes                = trim($body['shdes']);
$desFr                = trim($body['des_fr']);
$vsdate               = trim($body['vsdate']);
$apdate               = trim($body['apdate']);
$extrnlRateplanId     = (int) $body['extrnl_rateplan_id'];
$paymentMethodInd     = isset($body['payment_method_ind'])  ? trim($body['payment_method_ind'])  : 'P';
$chargingEngineCode   = isset($body['charging_engine_code']) ? (int) $body['charging_engine_code'] : 1;
$cesar                = isset($body['cesar'])                ? (int) $body['cesar']                : 0;

if (!in_array($cesar, [0, 1])) {
    sendResponse(400, ['success' => false, 'error' => 'Le champ "cesar" doit etre 0 ou 1']);
}

$pvCombiId           = null;
$cesarDes            = null;
$cesarAccessfee      = null;
$cesarPrmValueString = null;
if ($cesar === 1) {
    foreach (['cesar_des', 'cesar_accessfee', 'cesar_prm_value_string'] as $f) {
        if (!isset($body[$f]) || $body[$f] === '' || $body[$f] === null) {
            sendResponse(400, ['success' => false, 'error' => 'Champ requis quand cesar=1 : ' . $f]);
        }
    }
    $cesarDes            = trim($body['cesar_des']);
    $cesarAccessfee      = (float) $body['cesar_accessfee'];
    $cesarPrmValueString = trim($body['cesar_prm_value_string']);
}

// Validation des formats de dates
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

    $sql = "INSERT INTO RATEPLAN (
                TMCODE,
                DES,
                SHDES,
                TMGLOBAL,
                REC_VERSION,
                PRERATED_TAP_RP_IND,
                EXTRNL_RATEPLAN_ID,
                PDE_RATEPLAN,
                PROVISION_EXT,
                DES_FR,
                IMPORT_EXPORT_FLAG,
                SPECIAL_USAGE_IND,
                SERVICE_CLASS_ID,
                CHARGING_ENGINE_CODE,
                PAYMENT_METHOD_IND,
                BUSINESS_LINE_ID
            ) VALUES (
                :tmcode,
                :des,
                :shdes,
                'N',
                1,
                'N',
                :extrnl_rateplan_id,
                NULL,
                NULL,
                :des_fr,
                2,
                NULL,
                NULL,
                :charging_engine_code,
                :payment_method_ind,
                NULL
            )";

    $binds = [
        ':tmcode'               => $tmcode,
        ':des'                  => $des,
        ':shdes'                => $shdes,
        ':des_fr'               => $desFr,
        ':extrnl_rateplan_id'   => $extrnlRateplanId,
        ':charging_engine_code' => $chargingEngineCode,
        ':payment_method_ind'   => $paymentMethodInd,
    ];

    executeInsert($conn, $sql, $binds);

    // Insertion dans RATEPLAN_VERSION dans la même transaction
    $sqlRpv = "INSERT INTO RATEPLAN_VERSION (
                   VSCODE,
                   VSDATE,
                   STATUS,
                   TMRC,
                   APDATE,
                   CURRENCY,
                   REC_VERSION,
                   TMCODE,
                   PLCODE,
                   IMPORT_EXPORT_FLAG,
                   INT_SYNC,
                   INVALID_IND,
                   INVALIDATED_VSDATE,
                   INVALIDATED_VSCODE,
                   BACKDATE_IND,
                   CORE_SERVICE_FLAG
               ) VALUES (
                   0,
                   TO_DATE(:vsdate, 'DD-MM-YYYY'),
                   'W',
                   0,
                   TO_DATE(:apdate, 'DD-MM-YYYY HH24:MI:SS'),
                   72,
                   1,
                   :tmcode,
                   NULL,
                   2,
                   NULL,
                   NULL,
                   NULL,
                   NULL,
                   NULL,
                   NULL
               )";

    $stmtRpv = oci_parse($conn, $sqlRpv);
    if (!$stmtRpv) {
        $err = oci_error($conn);
        throw new Exception('Erreur parse SQL RATEPLAN_VERSION : ' . $err['message']);
    }
    oci_bind_by_name($stmtRpv, ':tmcode', $tmcode, -1, SQLT_INT);
    oci_bind_by_name($stmtRpv, ':vsdate', $vsdate);
    oci_bind_by_name($stmtRpv, ':apdate', $apdate);

    if (!oci_execute($stmtRpv, OCI_NO_AUTO_COMMIT)) {
        $err = oci_error($stmtRpv);
        oci_free_statement($stmtRpv);
        throw new Exception('Erreur INSERT RATEPLAN_VERSION : ' . $err['message']);
    }
    oci_free_statement($stmtRpv);

    // ── César (optionnel) ────────────────────────────────────────────────────
    if ($cesar === 1) {
        // Génération PV_COMBI_ID
        $pvCombiId = (int) fetchScalar($conn, 'SELECT NVL(MAX(PV_COMBI_ID),0)+1 FROM MPUPVTAB');

        // MPUPVTAB
        $stmtPvTab = oci_parse($conn, 'INSERT INTO MPUPVTAB (PV_COMBI_ID) VALUES (:pv_combi_id)');
        if (!$stmtPvTab) {
            $err = oci_error($conn);
            throw new Exception('Erreur parse MPUPVTAB cesar : ' . $err['message']);
        }
        oci_bind_by_name($stmtPvTab, ':pv_combi_id', $pvCombiId, -1, SQLT_INT);
        if (!oci_execute($stmtPvTab, OCI_NO_AUTO_COMMIT)) {
            $err = oci_error($stmtPvTab);
            oci_free_statement($stmtPvTab);
            throw new Exception('Erreur INSERT MPUPVTAB cesar : ' . $err['message']);
        }
        oci_free_statement($stmtPvTab);

        // TARIFF_SERVICE_PACKAGE (SPCODE=1532)
        $stmtTsp = oci_parse($conn,
            'INSERT INTO TARIFF_SERVICE_PACKAGE (TMCODE, SPCODE, TMVSCODE) VALUES (:tmcode, 1532, 0)');
        if (!$stmtTsp) {
            $err = oci_error($conn);
            throw new Exception('Erreur parse TARIFF_SERVICE_PACKAGE cesar : ' . $err['message']);
        }
        oci_bind_by_name($stmtTsp, ':tmcode', $tmcode, -1, SQLT_INT);
        if (!oci_execute($stmtTsp, OCI_NO_AUTO_COMMIT)) {
            $err = oci_error($stmtTsp);
            oci_free_statement($stmtTsp);
            throw new Exception('Erreur INSERT TARIFF_SERVICE_PACKAGE cesar : ' . $err['message']);
        }
        oci_free_statement($stmtTsp);

        // MPULKTM1 (SPCODE=1532, SNCODE=986)
        $stmtTmbCesar = oci_parse($conn,
            "INSERT INTO MPULKTM1 (
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
                :tmcode, 0, TO_DATE(:vsdate, 'DD-MM-YYYY'), 'W', 1532, 986,
                0, 0, NULL, NULL, NULL, 'P',
                'P', 'N', 'A', 'N',
                NULL, NULL, NULL, NULL,
                'X', 2,
                'Ca1 20%', 'Cd1 20%', 'ALL',
                NULL, NULL, NULL,
                'Ca1 20%', 'Cd1 20%', 'ALL',
                NULL, 'M', 1,
                :pv_combi_id, 'Y', 'N', 'N',
                1, NULL,
                3, NULL, NULL,
                NULL, NULL,
                1,
                NULL, 'M', NULL,
                986, 'B', NULL,
                NULL,
                1, 1, NULL,
                NULL,
                NULL, NULL, NULL
            )");
        if (!$stmtTmbCesar) {
            $err = oci_error($conn);
            throw new Exception('Erreur parse MPULKTM1 cesar : ' . $err['message']);
        }
        oci_bind_by_name($stmtTmbCesar, ':tmcode',      $tmcode,     -1, SQLT_INT);
        oci_bind_by_name($stmtTmbCesar, ':vsdate',      $vsdate);
        oci_bind_by_name($stmtTmbCesar, ':pv_combi_id', $pvCombiId,  -1, SQLT_INT);
        if (!oci_execute($stmtTmbCesar, OCI_NO_AUTO_COMMIT)) {
            $err = oci_error($stmtTmbCesar);
            oci_free_statement($stmtTmbCesar);
            throw new Exception('Erreur INSERT MPULKTM1 cesar : ' . $err['message']);
        }
        oci_free_statement($stmtTmbCesar);

        // MPULKPV1
        $stmtPvb = oci_parse($conn,
            "INSERT INTO MPULKPV1 (PV_COMBI_ID, VSCODE, SET_ID, DES, SUBSCRIPT, ACCESSFEE, REC_VERSION)
             VALUES (:pv_combi_id, 0, 1, :des, 0, :accessfee, 1)");
        if (!$stmtPvb) {
            $err = oci_error($conn);
            throw new Exception('Erreur parse MPULKPV1 cesar : ' . $err['message']);
        }
        oci_bind_by_name($stmtPvb, ':pv_combi_id', $pvCombiId,      -1, SQLT_INT);
        oci_bind_by_name($stmtPvb, ':des',          $cesarDes);
        oci_bind_by_name($stmtPvb, ':accessfee',    $cesarAccessfee);
        if (!oci_execute($stmtPvb, OCI_NO_AUTO_COMMIT)) {
            $err = oci_error($stmtPvb);
            oci_free_statement($stmtPvb);
            throw new Exception('Erreur INSERT MPULKPV1 cesar : ' . $err['message']);
        }
        oci_free_statement($stmtPvb);

        // MPULKPV2
        $stmtPvm = oci_parse($conn,
            "INSERT INTO MPULKPV2 (
                 PV_COMBI_ID, VSCODE, SET_ID, SCCODE,
                 PARAMETER_ID, LOWER_THRESHOLD,
                 PRM_VALUE_DATE, PRM_VALUE_NUMBER,
                 PRM_VALUE_STRING, REC_VERSION, PRM_VALUE_DES
             ) VALUES (
                 :pv_combi_id, 0, 1, 6,
                 479, 1,
                 NULL, NULL,
                 :prm_value_string, 1, :prm_value_des
             )");
        if (!$stmtPvm) {
            $err = oci_error($conn);
            throw new Exception('Erreur parse MPULKPV2 cesar : ' . $err['message']);
        }
        oci_bind_by_name($stmtPvm, ':pv_combi_id',      $pvCombiId,          -1, SQLT_INT);
        oci_bind_by_name($stmtPvm, ':prm_value_string', $cesarPrmValueString);
        oci_bind_by_name($stmtPvm, ':prm_value_des',    $cesarDes);
        if (!oci_execute($stmtPvm, OCI_NO_AUTO_COMMIT)) {
            $err = oci_error($stmtPvm);
            oci_free_statement($stmtPvm);
            throw new Exception('Erreur INSERT MPULKPV2 cesar : ' . $err['message']);
        }
        oci_free_statement($stmtPvm);
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

    $resp = [
        'success' => true,
        'tmcode'  => $tmcode,
        'message' => 'Plan tarifaire cree avec succes',
    ];
    if ($pvCombiId !== null) {
        $resp['cesar_pv_combi_id'] = $pvCombiId;
    }
    sendResponse(201, $resp);

} catch (Exception $e) {
    if (isset($conn) && $conn) {
        oci_rollback($conn);
        oci_close($conn);
    }
    sendResponse(500, ['success' => false, 'error' => $e->getMessage()]);
}
