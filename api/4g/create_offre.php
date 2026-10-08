<?php
/**
 * POST /4g/create_offre.php
 *
 * Crée une offre 4G complète en une seule transaction.
 *
 * Étapes :
 *   1. INSERT RATEPLAN
 *   2. INSERT RATEPLAN_VERSION                      (VSCODE=0, STATUS='W')
 *   3. INSERT 2 TSP + 2 MPULKTM1                   — options par défaut (si add_options=1)
 *   4. INSERT 1 TSP + 1 MPULKTM1 SPCODE=1373       — option Easybox (si easybox=1)
 *   5. Pour chaque formule :
 *        INSERT MPUSPTAB                            — création de la formule
 *        INSERT MPULKPXN × 6                        — services (SNCODEs 749,750,751,752,755,766)
 *        INSERT TARIFF_SERVICE_PACKAGE              — rattachement formule ↔ rateplan (TMVSCODE=0)
 *        INSERT MPULKTM1 × 6                        — billing (prix sur SNCODE 749)
 *        INSERT MPULKTM2 × 3                        — rating (SNCODEs 749, 752, 755)
 *      → séquence SPCODE mise à jour après la boucle
 *   → COMMIT global (ou ROLLBACK sur toute erreur)
 *
 * Corps JSON requis :
 *   des                (string) — description longue (ex: "4G Office Hyb 500Go")
 *   shdes              (string) — description courte unique (ex: "4OfG5")
 *   des_fr             (string) — segment marché (ex: "B2C", "B2B", "TEST")
 *   vsdate             (string) — date de version dd-mm-yyyy (ex: "19-03-2024")
 *   apdate             (string) — date d'application dd-mm-yyyy hh:mm:ss
 *   extrnl_rateplan_id (int)    — identifiant externe (1=postpaid, 2=prepaid)
 *   formules           (array)  — liste des formules (1 à 20) :
 *     [
 *       { "des": "4G Office Hyb 500Go", "shdes": "4O500", "accessfee": 37190 },
 *       ...
 *     ]
 *     des       (string) — nom unique dans MPUSPTAB
 *     shdes     (string) — exactement 5 caractères
 *     accessfee (int)    — prix en centimes, porté par SNCODE 749 dans MPULKTM1
 *
 * Corps JSON optionnel :
 *   payment_method_ind   (string) — défaut : "P"
 *   charging_engine_code (int)    — défaut : 1
 *   add_options          (int)    — 0 (défaut) : sans options par défaut
 *                                   1 : insère 2 TSP + 2 MPULKTM1 (SPCODE 999 et 1645)
 *   easybox              (int)    — 0 (défaut) : sans option Easybox
 *                                   1 : insère 1 TSP + 1 MPULKTM1 (SPCODE 1373, SNCODE 944)
 *
 * Réponse :
 *   {
 *     "success": true,
 *     "tmcode": 1256,
 *     "formules": [
 *       { "spcode": 1826, "des": "4G Office Hyb 500Go", "shdes": "4O500" }
 *     ],
 *     "etapes": {
 *       "rateplan":         "OK",
 *       "rateplan_version": "OK",
 *       "options":          "OK (2 TSP, 2 TM1)",
 *       "easybox":          "OK (1 TSP, 1 TM1)",
 *       "formules":         "OK (1 formule(s), 6 PXN + 1 TSP + 6 TM1 + 3 TM2 par formule)"
 *     },
 *     "message": "Offre 4G creee avec succes"
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
requireFields($body, ['des', 'shdes', 'des_fr', 'vsdate', 'apdate', 'extrnl_rateplan_id', 'formules']);

$des                = trim($body['des']);
$shdes              = trim($body['shdes']);
$desFr              = trim($body['des_fr']);
$vsdate             = trim($body['vsdate']);
$apdate             = trim($body['apdate']);
$extrnlRateplanId   = (int) $body['extrnl_rateplan_id'];
$formules           = $body['formules'];
$paymentMethodInd   = isset($body['payment_method_ind'])   ? trim($body['payment_method_ind'])   : 'P';
$chargingEngineCode = isset($body['charging_engine_code']) ? (int) $body['charging_engine_code'] : 1;
$addOptions         = isset($body['add_options'])          ? (int) $body['add_options']          : 0;
$easybox            = isset($body['easybox'])              ? (int) $body['easybox']              : 0;

// ── Validations ───────────────────────────────────────────────────────────────
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
if (!is_array($formules) || empty($formules)) {
    sendResponse(400, ['success' => false, 'error' => 'Le champ "formules" doit etre un tableau non vide']);
}
if (count($formules) > 20) {
    sendResponse(400, ['success' => false, 'error' => 'Maximum 20 formules par appel']);
}
foreach ($formules as $i => $f) {
    if (!isset($f['des']) || trim($f['des']) === '') {
        sendResponse(400, ['success' => false, 'error' => 'Champ "des" manquant dans formules[' . $i . ']']);
    }
    if (!isset($f['shdes']) || trim($f['shdes']) === '') {
        sendResponse(400, ['success' => false, 'error' => 'Champ "shdes" manquant dans formules[' . $i . ']']);
    }
    if (strlen(trim($f['shdes'])) !== 5) {
        sendResponse(400, ['success' => false,
            'error' => 'Format shdes invalide dans formules[' . $i . '] : doit contenir exactement 5 caracteres']);
    }
    if (!isset($f['accessfee']) || $f['accessfee'] === '' || $f['accessfee'] === null) {
        sendResponse(400, ['success' => false, 'error' => 'Champ "accessfee" manquant dans formules[' . $i . ']']);
    }
}

// ── Données fixes billing ─────────────────────────────────────────────────────
$sncodesPxn = [749, 750, 751, 752, 755, 766];

$tm1Rows = [
    ['sncode' => 752, 'usg_cat' => 'Ca1 20%', 'usg_code' => 'Cd1 20%', 'usg_type' => 'ALL', 'def_pay_usg' => 1, 'def_type_ctrl' => 2,    'usg_gl' => 1],
    ['sncode' => 751, 'usg_cat' => null,       'usg_code' => null,       'usg_type' => null,  'def_pay_usg' => 3, 'def_type_ctrl' => null, 'usg_gl' => null],
    ['sncode' => 750, 'usg_cat' => null,       'usg_code' => null,       'usg_type' => null,  'def_pay_usg' => 3, 'def_type_ctrl' => null, 'usg_gl' => null],
    ['sncode' => 755, 'usg_cat' => 'Ca1 20%', 'usg_code' => 'Cd1 20%', 'usg_type' => 'ALL', 'def_pay_usg' => 1, 'def_type_ctrl' => 2,    'usg_gl' => 1],
    ['sncode' => 766, 'usg_cat' => null,       'usg_code' => null,       'usg_type' => null,  'def_pay_usg' => 3, 'def_type_ctrl' => null, 'usg_gl' => null],
    ['sncode' => 749, 'usg_cat' => 'Ca1 20%', 'usg_code' => 'Cd1 20%', 'usg_type' => 'ALL', 'def_pay_usg' => 1, 'def_type_ctrl' => 2,    'usg_gl' => 1],
];

$tm2Rows = [
    ['sncode' => 752, 'svlcode' => 'GSMT**B*****SVO*', 'upcode' => 47, 'ricode' => 238, 'usage_type_id' => 2],
    ['sncode' => 755, 'svlcode' => 'GSMT**B*****SAF*', 'upcode' => 49, 'ricode' => 248, 'usage_type_id' => 2],
    ['sncode' => 749, 'svlcode' => 'GSMTWIB*****S***', 'upcode' => 47, 'ricode' => 238, 'usage_type_id' => 2],
];

// ── SQL templates ─────────────────────────────────────────────────────────────
$sqlMpusptab = "INSERT INTO MPUSPTAB (
                    SPCODE, DES, SHDES, REC_VERSION,
                    PDE_SERVICE_PACKAGE, DES_FR, IMPORT_EXPORT_FLAG,
                    SPECIAL_USAGE_IND, CHARGING_ENGINE_CODE, ATOMIC_SERVICE_PACKAGE
                ) VALUES (
                    :spcode, :des, :shdes, 1,
                    NULL, NULL, 2,
                    NULL, 1, NULL
                )";

$sqlPxn = "INSERT INTO MPULKPXN (SPCODE, SNCODE, REC_VERSION)
           VALUES (:spcode, :sncode, 1)";

$sqlTspFormule = "INSERT INTO TARIFF_SERVICE_PACKAGE (
                      TMCODE, SPCODE, TMVSCODE, SERVICE_PACKAGE_DEF, ATOMIC_SERVICE_PACKAGE
                  ) VALUES (:tmcode, :spcode, 0, NULL, NULL)";

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
               0, :accessfee, NULL, NULL, NULL, NULL,
               'P', 'N', 'A', 'N',
               NULL, NULL, NULL, NULL,
               NULL, NULL,
               'Ca1 20%', 'Cd1 20%', 'ALL',
               :usg_cat, :usg_code, :usg_type,
               'Ca1 20%', 'Cd1 20%', 'ALL',
               NULL, 'M', 1,
               NULL, 'N', 'N', 'N',
               1, NULL,
               :def_pay_usg, NULL, 0,
               NULL, NULL,
               1,
               NULL, 'M', NULL,
               :service_prio, 'B', NULL,
               :def_type_ctrl,
               1, 1, :usg_gl,
               NULL,
               NULL, NULL, NULL
           )";

$sqlTm2 = "INSERT INTO MPULKTM2 (
               TMCODE, VSCODE, VSDATE, STATUS, SPCODE, SNCODE,
               SVLCODE, RATEIND, UPCODE, RICODE,
               REC_VERSION, USAGE_TYPE_ID, CP_PRICELIST_ID, FALLBACK_SRV_FLAG
           ) VALUES (
               :tmcode, 0, TO_DATE(:vsdate, 'DD-MM-YYYY'), 'W', :spcode, :sncode,
               :svlcode, 'Y', :upcode, :ricode,
               1, :usage_type_id, NULL, NULL
           )";

// ── Transaction ───────────────────────────────────────────────────────────────
$etapes = [];

try {
    $conn = getConnection();

    // Vérification doublon RATEPLAN
    $stmtChk = oci_parse($conn, 'SELECT COUNT(*) FROM RATEPLAN WHERE SHDES = :shdes');
    oci_bind_by_name($stmtChk, ':shdes', $shdes);
    oci_execute($stmtChk, OCI_NO_AUTO_COMMIT);
    $rowChk = oci_fetch_array($stmtChk, OCI_NUM);
    oci_free_statement($stmtChk);
    if ((int) $rowChk[0] > 0) {
        oci_close($conn);
        sendResponse(409, ['success' => false, 'error' => 'Un plan tarifaire avec ce SHDES existe deja : ' . $shdes]);
    }

    // Vérification doublons MPUSPTAB pour chaque formule
    foreach ($formules as $i => $f) {
        $fShdes = trim($f['shdes']);
        $stmtChk = oci_parse($conn, 'SELECT COUNT(*) FROM MPUSPTAB WHERE SHDES = :shdes');
        if (!$stmtChk) {
            $err = oci_error($conn);
            throw new Exception('Erreur parse verification SHDES formule : ' . $err['message']);
        }
        oci_bind_by_name($stmtChk, ':shdes', $fShdes);
        oci_execute($stmtChk, OCI_NO_AUTO_COMMIT);
        $rowChk = oci_fetch_array($stmtChk, OCI_NUM);
        oci_free_statement($stmtChk);
        if ((int) $rowChk[0] > 0) {
            oci_close($conn);
            sendResponse(409, ['success' => false,
                'error' => 'Une formule avec ce SHDES existe deja : ' . $fShdes]);
        }
    }

    // ── Séquence TMCODE ───────────────────────────────────────────────────────
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

    // ── RATEPLAN ──────────────────────────────────────────────────────────────
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
        )", [
        ':tmcode'               => $tmcode,
        ':des'                  => $des,
        ':shdes'                => $shdes,
        ':des_fr'               => $desFr,
        ':extrnl_rateplan_id'   => $extrnlRateplanId,
        ':charging_engine_code' => $chargingEngineCode,
        ':payment_method_ind'   => $paymentMethodInd,
    ]);
    $etapes['rateplan'] = 'OK';

    // ── RATEPLAN_VERSION — VSCODE=0, STATUS='W' ───────────────────────────────
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
    $etapes['rateplan_version'] = 'OK';

    // ── Options par défaut (add_options=1) ────────────────────────────────────
    if ($addOptions === 1) {
        $tspRows = [
            ['spcode' => 999,  'atomic' => null],
            ['spcode' => 1645, 'atomic' => 'X'],
        ];
        $sqlTspOpt = "INSERT INTO TARIFF_SERVICE_PACKAGE
                          (TMCODE, SPCODE, TMVSCODE, SERVICE_PACKAGE_DEF, ATOMIC_SERVICE_PACKAGE)
                      VALUES (:tmcode, :spcode, 0, NULL, :atomic)";
        foreach ($tspRows as $tsp) {
            $stmtTsp = oci_parse($conn, $sqlTspOpt);
            if (!$stmtTsp) {
                $err = oci_error($conn);
                throw new Exception('Erreur parse TARIFF_SERVICE_PACKAGE option : ' . $err['message']);
            }
            oci_bind_by_name($stmtTsp, ':tmcode', $tmcode,       -1, SQLT_INT);
            oci_bind_by_name($stmtTsp, ':spcode', $tsp['spcode'], -1, SQLT_INT);
            oci_bind_by_name($stmtTsp, ':atomic', $tsp['atomic'], -1, SQLT_CHR);
            if (!oci_execute($stmtTsp, OCI_NO_AUTO_COMMIT)) {
                $err = oci_error($stmtTsp);
                oci_free_statement($stmtTsp);
                throw new Exception('Erreur INSERT TARIFF_SERVICE_PACKAGE option (SPCODE ' . $tsp['spcode'] . ') : ' . $err['message']);
            }
            oci_free_statement($stmtTsp);
        }

        $tm1OptRows = [
            ['spcode' => 999,  'sncode' => 767,  'proind' => 'N', 'csind' => 'X',  'clcode' => 2,    'service_priority' => 767],
            ['spcode' => 1645, 'sncode' => 1052, 'proind' => 'Y', 'csind' => null, 'clcode' => null, 'service_priority' => 0],
        ];
        $sqlTm1Opt = "INSERT INTO MPULKTM1 (
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
        foreach ($tm1OptRows as $row) {
            $stmt = oci_parse($conn, $sqlTm1Opt);
            if (!$stmt) {
                $err = oci_error($conn);
                throw new Exception('Erreur parse MPULKTM1 option : ' . $err['message']);
            }
            oci_bind_by_name($stmt, ':tmcode',           $tmcode,                 -1, SQLT_INT);
            oci_bind_by_name($stmt, ':vsdate',           $vsdate);
            oci_bind_by_name($stmt, ':spcode',           $row['spcode'],           -1, SQLT_INT);
            oci_bind_by_name($stmt, ':sncode',           $row['sncode'],           -1, SQLT_INT);
            oci_bind_by_name($stmt, ':proind',           $row['proind']);
            oci_bind_by_name($stmt, ':csind',            $row['csind'],            -1, SQLT_CHR);
            oci_bind_by_name($stmt, ':clcode',           $row['clcode'],           -1, ociType($row['clcode']));
            oci_bind_by_name($stmt, ':service_priority', $row['service_priority'], -1, SQLT_INT);
            if (!oci_execute($stmt, OCI_NO_AUTO_COMMIT)) {
                $err = oci_error($stmt);
                oci_free_statement($stmt);
                throw new Exception('Erreur INSERT MPULKTM1 option (SPCODE ' . $row['spcode'] . ') : ' . $err['message']);
            }
            oci_free_statement($stmt);
        }
        $etapes['options'] = 'OK (2 TSP, 2 TM1)';
    }

    // ── Option Easybox (easybox=1) ────────────────────────────────────────────
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
        $etapes['easybox'] = 'OK (1 TSP, 1 TM1)';
    }

    // ── Mise à jour séquence TMCODE ───────────────────────────────────────────
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

    // ── Séquence SPCODE ───────────────────────────────────────────────────────
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

    // ── Formules ──────────────────────────────────────────────────────────────
    $result = [];

    foreach ($formules as $i => $f) {
        $fDes      = trim($f['des']);
        $fShdes    = trim($f['shdes']);
        $spcode    = $firstSpcode + $i;
        $accessfee = (int) $f['accessfee'];

        // MPUSPTAB
        $stmtSpt = oci_parse($conn, $sqlMpusptab);
        if (!$stmtSpt) {
            $err = oci_error($conn);
            throw new Exception('Erreur parse MPUSPTAB : ' . $err['message']);
        }
        oci_bind_by_name($stmtSpt, ':spcode', $spcode, -1, SQLT_INT);
        oci_bind_by_name($stmtSpt, ':des',    $fDes);
        oci_bind_by_name($stmtSpt, ':shdes',  $fShdes);
        if (!oci_execute($stmtSpt, OCI_NO_AUTO_COMMIT)) {
            $err = oci_error($stmtSpt);
            oci_free_statement($stmtSpt);
            throw new Exception('Erreur INSERT MPUSPTAB (' . $fShdes . ') : ' . $err['message']);
        }
        oci_free_statement($stmtSpt);

        // MPULKPXN — 6 SNCODEs
        foreach ($sncodesPxn as $sncode) {
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

        // TARIFF_SERVICE_PACKAGE
        $stmtTsp = oci_parse($conn, $sqlTspFormule);
        if (!$stmtTsp) {
            $err = oci_error($conn);
            throw new Exception('Erreur parse TARIFF_SERVICE_PACKAGE : ' . $err['message']);
        }
        oci_bind_by_name($stmtTsp, ':tmcode', $tmcode, -1, SQLT_INT);
        oci_bind_by_name($stmtTsp, ':spcode', $spcode, -1, SQLT_INT);
        if (!oci_execute($stmtTsp, OCI_NO_AUTO_COMMIT)) {
            $err = oci_error($stmtTsp);
            oci_free_statement($stmtTsp);
            throw new Exception('Erreur INSERT TARIFF_SERVICE_PACKAGE (SPCODE ' . $spcode . ') : ' . $err['message']);
        }
        oci_free_statement($stmtTsp);

        // MPULKTM1 — 6 lignes
        foreach ($tm1Rows as $row) {
            $sncode      = $row['sncode'];
            $accessfeeRow = ($sncode === 749) ? $accessfee : 0;
            $servicePrio  = $sncode;
            $usgCat       = $row['usg_cat'];
            $usgCode      = $row['usg_code'];
            $usgType      = $row['usg_type'];
            $defPayUsg    = $row['def_pay_usg'];
            $defTypCtrl   = $row['def_type_ctrl'];
            $usgGl        = $row['usg_gl'];

            $stmt = oci_parse($conn, $sqlTm1);
            if (!$stmt) {
                $err = oci_error($conn);
                throw new Exception('Erreur parse MPULKTM1 : ' . $err['message']);
            }
            oci_bind_by_name($stmt, ':tmcode',       $tmcode,      -1, SQLT_INT);
            oci_bind_by_name($stmt, ':vsdate',       $vsdate);
            oci_bind_by_name($stmt, ':spcode',       $spcode,      -1, SQLT_INT);
            oci_bind_by_name($stmt, ':sncode',       $sncode,      -1, SQLT_INT);
            oci_bind_by_name($stmt, ':accessfee',    $accessfeeRow, -1, SQLT_INT);
            oci_bind_by_name($stmt, ':usg_cat',      $usgCat,      -1, SQLT_CHR);
            oci_bind_by_name($stmt, ':usg_code',     $usgCode,     -1, SQLT_CHR);
            oci_bind_by_name($stmt, ':usg_type',     $usgType,     -1, SQLT_CHR);
            oci_bind_by_name($stmt, ':def_pay_usg',  $defPayUsg,   -1, SQLT_INT);
            oci_bind_by_name($stmt, ':service_prio', $servicePrio, -1, SQLT_INT);
            oci_bind_by_name($stmt, ':def_type_ctrl', $defTypCtrl, -1, ociType($defTypCtrl));
            oci_bind_by_name($stmt, ':usg_gl',       $usgGl,       -1, ociType($usgGl));
            if (!oci_execute($stmt, OCI_NO_AUTO_COMMIT)) {
                $err = oci_error($stmt);
                oci_free_statement($stmt);
                throw new Exception('Erreur INSERT MPULKTM1 (SPCODE ' . $spcode
                    . ', SNCODE ' . $sncode . ') : ' . $err['message']);
            }
            oci_free_statement($stmt);
        }

        // MPULKTM2 — 3 lignes
        foreach ($tm2Rows as $row) {
            $sncode      = $row['sncode'];
            $svlcode     = $row['svlcode'];
            $upcode      = $row['upcode'];
            $ricode      = $row['ricode'];
            $usageTypeId = $row['usage_type_id'];

            $stmt = oci_parse($conn, $sqlTm2);
            if (!$stmt) {
                $err = oci_error($conn);
                throw new Exception('Erreur parse MPULKTM2 : ' . $err['message']);
            }
            oci_bind_by_name($stmt, ':tmcode',        $tmcode,      -1, SQLT_INT);
            oci_bind_by_name($stmt, ':vsdate',        $vsdate);
            oci_bind_by_name($stmt, ':spcode',        $spcode,      -1, SQLT_INT);
            oci_bind_by_name($stmt, ':sncode',        $sncode,      -1, SQLT_INT);
            oci_bind_by_name($stmt, ':svlcode',       $svlcode);
            oci_bind_by_name($stmt, ':upcode',        $upcode,      -1, SQLT_INT);
            oci_bind_by_name($stmt, ':ricode',        $ricode,      -1, SQLT_INT);
            oci_bind_by_name($stmt, ':usage_type_id', $usageTypeId, -1, SQLT_INT);
            if (!oci_execute($stmt, OCI_NO_AUTO_COMMIT)) {
                $err = oci_error($stmt);
                oci_free_statement($stmt);
                throw new Exception('Erreur INSERT MPULKTM2 (SPCODE ' . $spcode
                    . ', SNCODE ' . $sncode . ') : ' . $err['message']);
            }
            oci_free_statement($stmt);
        }

        $result[] = ['spcode' => $spcode, 'des' => $fDes, 'shdes' => $fShdes];
    }

    // ── Mise à jour séquence SPCODE ───────────────────────────────────────────
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

    $nf = count($formules);
    $etapes['formules'] = 'OK (' . $nf . ' formule(s), 6 PXN + 1 TSP + 6 TM1 + 3 TM2 par formule)';

    oci_commit($conn);
    oci_close($conn);

    sendResponse(201, [
        'success'  => true,
        'tmcode'   => $tmcode,
        'formules' => $result,
        'etapes'   => $etapes,
        'message'  => 'Offre 4G creee avec succes',
    ]);

} catch (Exception $e) {
    if (isset($conn) && $conn) {
        oci_rollback($conn);
        oci_close($conn);
    }
    sendResponse(500, ['success' => false, 'etapes' => $etapes, 'error' => $e->getMessage()]);
}
