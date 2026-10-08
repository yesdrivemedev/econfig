<?php
/**
 * POST /mobile/create_offre.php
 *
 * Crée une offre mobile complète en une seule transaction.
 *
 * Étapes :
 *   1. INSERT RATEPLAN
 *   2. INSERT RATEPLAN_VERSION                    (VSCODE=0, STATUS='W')
 *   3. INSERT 48 TSP + 90 MPULKTM1 + 29 MPULKTM2 — options par défaut (si add_options=1)
 *   4. Pour chaque formule :
 *        INSERT MPUSPTAB                          — création de la formule
 *        INSERT MPULKPXN × 15                     — services (SNCODEs fixes)
 *        INSERT TARIFF_SERVICE_PACKAGE            — rattachement formule ↔ rateplan (TMVSCODE=0)
 *        INSERT MPULKTM1 × 15                     — billing
 *        INSERT MPULKTM2 × 14                     — rating
 *      → séquence SPCODE mise à jour après la boucle
 *   → COMMIT global (ou ROLLBACK sur toute erreur)
 *
 * Corps JSON requis :
 *   des                (string) — description longue (ex: "Mobile Confort")
 *   shdes              (string) — description courte unique (ex: "MOBC")
 *   des_fr             (string) — segment marché (ex: "B2C", "B2B", "TEST")
 *   vsdate             (string) — date de version dd-mm-yyyy (ex: "27-12-2025")
 *   apdate             (string) — date d'application dd-mm-yyyy hh:mm:ss
 *   extrnl_rateplan_id (int)    — identifiant externe (1=postpaid, 2=prepaid)
 *   formules           (array)  — liste des formules (1 à 20) :
 *     [
 *       { "des": "BR MIX 3", "shdes": "D_M11", "accessfee": 2119 },
 *       ...
 *     ]
 *     des       (string) — nom unique dans MPUSPTAB
 *     shdes     (string) — 5 caractères, format D_*** (ex: "D_M11")
 *     accessfee (int)    — prix en centimes, porté par SNCODE 191 dans MPULKTM1
 *
 * Corps JSON optionnel :
 *   payment_method_ind   (string) — défaut : "P"
 *   charging_engine_code (int)    — défaut : 1
 *   add_options          (int)    — 1 (défaut) : insère 48 TSP + 90 TM1 + 29 TM2 par défaut
 *                                   0 : rateplan + formules uniquement
 *
 * Réponse :
 *   {
 *     "success": true,
 *     "tmcode": 861,
 *     "formules": [
 *       { "spcode": 1454, "des": "BR MIX 3", "shdes": "D_M11" }
 *     ],
 *     "etapes": {
 *       "rateplan":         "OK",
 *       "rateplan_version": "OK",
 *       "options":          "OK (48 TSP, 90 TM1, 29 TM2)",
 *       "formules":         "OK (1 formule(s), 15 PXN + 1 TSP + 15 TM1 + 14 TM2 par formule)"
 *     },
 *     "message": "Offre mobile creee avec succes"
 *   }
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/options_data.php';

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
$addOptions         = isset($body['add_options'])          ? (int) $body['add_options']          : 1;

// ── Validations ───────────────────────────────────────────────────────────────
if (!in_array($addOptions, [0, 1])) {
    sendResponse(400, ['success' => false, 'error' => 'add_options doit etre 0 ou 1']);
}
if (!preg_match('/^\d{2}-\d{2}-\d{4}$/', $vsdate)) {
    sendResponse(400, ['success' => false, 'error' => 'Format vsdate invalide. Utilisez dd-mm-yyyy (ex: 27-12-2025)']);
}
if (!preg_match('/^\d{2}-\d{2}-\d{4} \d{2}:\d{2}:\d{2}$/', $apdate)) {
    sendResponse(400, ['success' => false, 'error' => 'Format apdate invalide. Utilisez dd-mm-yyyy hh:mm:ss (ex: 27-12-2025 10:24:32)']);
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
    if (!preg_match('/^D_[A-Z0-9]{3}$/', strtoupper(trim($f['shdes'])))) {
        sendResponse(400, ['success' => false,
            'error' => 'Format shdes invalide dans formules[' . $i . '] : doit etre D_*** (ex: D_M11)']);
    }
    if (!isset($f['accessfee']) || $f['accessfee'] === '' || $f['accessfee'] === null) {
        sendResponse(400, ['success' => false, 'error' => 'Champ "accessfee" manquant dans formules[' . $i . ']']);
    }
}

// ── Données fixes billing ─────────────────────────────────────────────────────
$sncodes = [1, 2, 3, 4, 5, 6, 8, 9, 10, 11, 50, 52, 53, 61, 191];

$tm1Defs = [
    1   => ['proind' => 'Y', 'usgserv' => true],
    2   => ['proind' => 'Y', 'usgserv' => true],
    3   => ['proind' => 'Y', 'usgserv' => true],
    4   => ['proind' => 'Y', 'usgserv' => true],
    5   => ['proind' => 'Y', 'usgserv' => true],
    6   => ['proind' => 'Y', 'usgserv' => true],
    8   => ['proind' => 'Y', 'usgserv' => true],
    9   => ['proind' => 'Y', 'usgserv' => true],
    10  => ['proind' => 'Y', 'usgserv' => true],
    11  => ['proind' => 'Y', 'usgserv' => true],
    50  => ['proind' => 'Y', 'usgserv' => true],
    52  => ['proind' => 'Y', 'usgserv' => true],
    53  => ['proind' => 'N', 'usgserv' => true],
    61  => ['proind' => 'N', 'usgserv' => true],
    191 => ['proind' => 'N', 'usgserv' => false],
];

$tm2Defs = [
    1  => ['svlcode' => 'GSMT11B*****S***', 'usage_type_id' => 2],
    2  => ['svlcode' => 'GSMT21B*****S***', 'usage_type_id' => 2],
    3  => ['svlcode' => 'GSMT12B*****S***', 'usage_type_id' => 2],
    4  => ['svlcode' => 'GSMT**B*****S41*', 'usage_type_id' => 4],
    5  => ['svlcode' => 'GSMT**B*****S42*', 'usage_type_id' => 4],
    6  => ['svlcode' => 'GSMT**B*****S11*', 'usage_type_id' => 4],
    8  => ['svlcode' => 'GSMT**B*****S21*', 'usage_type_id' => 4],
    9  => ['svlcode' => 'GSMT**B*****S29*', 'usage_type_id' => 4],
    10 => ['svlcode' => 'GSMT**B*****S2A*', 'usage_type_id' => 4],
    11 => ['svlcode' => 'GSMT**B*****S2B*', 'usage_type_id' => 4],
    50 => ['svlcode' => 'GSMT22B*****S***', 'usage_type_id' => 2],
    52 => ['svlcode' => 'GSMT**B*****S61*', 'usage_type_id' => 4],
    53 => ['svlcode' => 'GSMT**B*****S51*', 'usage_type_id' => 4],
    61 => ['svlcode' => 'GSMTB0B*****S***', 'usage_type_id' => 2],
];

// ── SQL templates ─────────────────────────────────────────────────────────────
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
           ) VALUES (:tmcode, :spcode, 0, NULL, NULL)";

$sqlTm1 = "INSERT INTO MPULKTM1 (
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
               :tmcode, 0, TO_DATE(:vsdate, 'DD-MM-YYYY'), 'W', :spcode, :sncode,
               0, :accessfee_row, NULL, NULL, NULL, NULL,
               'P', :proind, 'A', 'N',
               NULL, NULL, NULL, NULL, NULL, NULL,
               'Ca1 20%', 'Cd1 20%', 'ALL',
               :usgserv_catcode, :usgserv_code, :usgserv_type,
               'Ca1 20%', 'Cd1 20%', 'ALL',
               NULL, 'M', 1,
               NULL, 'N', 'N', 'N',
               1, NULL,
               1, NULL, 0,
               NULL, NULL,
               1,
               NULL, 'M', NULL,
               :service_priority, 'B', NULL,
               :def_type_of_control,
               1, 1, :usg_gl_acc_pack_id,
               NULL,
               NULL, NULL, NULL
           )";

$sqlTm2 = "INSERT INTO MPULKTM2 (
               TMCODE, VSCODE, VSDATE, STATUS, SPCODE, SNCODE,
               SVLCODE, RATEIND, UPCODE, RICODE,
               REC_VERSION, USAGE_TYPE_ID, CP_PRICELIST_ID, FALLBACK_SRV_FLAG
           ) VALUES (
               :tmcode, 0, TO_DATE(:vsdate, 'DD-MM-YYYY'), 'W', :spcode, :sncode,
               :svlcode, 'Y', 57, 253,
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
        $fShdes = strtoupper(trim($f['shdes']));
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

        $etapes['options'] = 'OK (' . count($optData['tsp']) . ' TSP, '
            . count($optData['tm1']) . ' TM1, '
            . count($optData['tm2']) . ' TM2)';
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
        $des       = trim($f['des']);
        $fShdes    = strtoupper(trim($f['shdes']));
        $spcode    = $firstSpcode + $i;
        $accessfee = (float) $f['accessfee'];

        // MPUSPTAB
        $stmtSpt = oci_parse($conn, $sqlMpusptab);
        if (!$stmtSpt) {
            $err = oci_error($conn);
            throw new Exception('Erreur parse MPUSPTAB : ' . $err['message']);
        }
        oci_bind_by_name($stmtSpt, ':spcode', $spcode, -1, SQLT_INT);
        oci_bind_by_name($stmtSpt, ':des',    $des);
        oci_bind_by_name($stmtSpt, ':shdes',  $fShdes);
        if (!oci_execute($stmtSpt, OCI_NO_AUTO_COMMIT)) {
            $err = oci_error($stmtSpt);
            oci_free_statement($stmtSpt);
            throw new Exception('Erreur INSERT MPUSPTAB (' . $fShdes . ') : ' . $err['message']);
        }
        oci_free_statement($stmtSpt);

        // MPULKPXN — 15 SNCODEs
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

        // TARIFF_SERVICE_PACKAGE
        $stmtTspF = oci_parse($conn, $sqlTsp);
        if (!$stmtTspF) {
            $err = oci_error($conn);
            throw new Exception('Erreur parse TARIFF_SERVICE_PACKAGE : ' . $err['message']);
        }
        oci_bind_by_name($stmtTspF, ':tmcode', $tmcode, -1, SQLT_INT);
        oci_bind_by_name($stmtTspF, ':spcode', $spcode, -1, SQLT_INT);
        if (!oci_execute($stmtTspF, OCI_NO_AUTO_COMMIT)) {
            $err = oci_error($stmtTspF);
            oci_free_statement($stmtTspF);
            throw new Exception('Erreur INSERT TARIFF_SERVICE_PACKAGE (SPCODE ' . $spcode . ') : ' . $err['message']);
        }
        oci_free_statement($stmtTspF);

        // MPULKTM1 — 15 lignes
        foreach ($tm1Defs as $sncode => $def) {
            $sncodeVal        = $sncode;
            $proindVal        = $def['proind'];
            $accessfeeRow     = ($sncode === 191) ? $accessfee : 0;
            $usgservCatcode   = $def['usgserv'] ? 'Ca1 20%' : null;
            $usgservCode      = $def['usgserv'] ? 'Cd1 20%' : null;
            $usgservType      = $def['usgserv'] ? 'ALL'     : null;
            $defTypeOfControl = ($sncode === 191) ? null : 2;
            $usgGlAccPackId   = ($sncode === 191) ? null : 1;
            $servicePriority  = $sncode;

            $stmt = oci_parse($conn, $sqlTm1);
            if (!$stmt) {
                $err = oci_error($conn);
                throw new Exception('Erreur parse MPULKTM1 : ' . $err['message']);
            }
            oci_bind_by_name($stmt, ':tmcode',             $tmcode,           -1, SQLT_INT);
            oci_bind_by_name($stmt, ':vsdate',             $vsdate);
            oci_bind_by_name($stmt, ':spcode',             $spcode,           -1, SQLT_INT);
            oci_bind_by_name($stmt, ':sncode',             $sncodeVal,        -1, SQLT_INT);
            oci_bind_by_name($stmt, ':accessfee_row',      $accessfeeRow);
            oci_bind_by_name($stmt, ':proind',             $proindVal);
            oci_bind_by_name($stmt, ':usgserv_catcode',    $usgservCatcode,   -1);
            oci_bind_by_name($stmt, ':usgserv_code',       $usgservCode,      -1);
            oci_bind_by_name($stmt, ':usgserv_type',       $usgservType,      -1);
            oci_bind_by_name($stmt, ':service_priority',   $servicePriority,  -1, SQLT_INT);
            oci_bind_by_name($stmt, ':def_type_of_control', $defTypeOfControl, -1, ociType($defTypeOfControl));
            oci_bind_by_name($stmt, ':usg_gl_acc_pack_id',  $usgGlAccPackId,   -1, ociType($usgGlAccPackId));
            if (!oci_execute($stmt, OCI_NO_AUTO_COMMIT)) {
                $err = oci_error($stmt);
                oci_free_statement($stmt);
                throw new Exception('Erreur INSERT MPULKTM1 (SPCODE ' . $spcode
                    . ', SNCODE ' . $sncode . ') : ' . $err['message']);
            }
            oci_free_statement($stmt);
        }

        // MPULKTM2 — 14 lignes (SNCODE 191 absent)
        foreach ($tm2Defs as $sncode => $tm2) {
            $sncodeVal   = $sncode;
            $svlcodeVal  = $tm2['svlcode'];
            $usageTypeId = $tm2['usage_type_id'];

            $stmt = oci_parse($conn, $sqlTm2);
            if (!$stmt) {
                $err = oci_error($conn);
                throw new Exception('Erreur parse MPULKTM2 : ' . $err['message']);
            }
            oci_bind_by_name($stmt, ':tmcode',        $tmcode,     -1, SQLT_INT);
            oci_bind_by_name($stmt, ':vsdate',        $vsdate);
            oci_bind_by_name($stmt, ':spcode',        $spcode,     -1, SQLT_INT);
            oci_bind_by_name($stmt, ':sncode',        $sncodeVal,  -1, SQLT_INT);
            oci_bind_by_name($stmt, ':svlcode',       $svlcodeVal);
            oci_bind_by_name($stmt, ':usage_type_id', $usageTypeId, -1, SQLT_INT);
            if (!oci_execute($stmt, OCI_NO_AUTO_COMMIT)) {
                $err = oci_error($stmt);
                oci_free_statement($stmt);
                throw new Exception('Erreur INSERT MPULKTM2 (SPCODE ' . $spcode
                    . ', SNCODE ' . $sncode . ') : ' . $err['message']);
            }
            oci_free_statement($stmt);
        }

        $result[] = ['spcode' => $spcode, 'des' => $des, 'shdes' => $fShdes];
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
    $etapes['formules'] = 'OK (' . $nf . ' formule(s), 15 PXN + 1 TSP + 15 TM1 + 14 TM2 par formule)';

    oci_commit($conn);
    oci_close($conn);

    sendResponse(201, [
        'success'  => true,
        'tmcode'   => $tmcode,
        'formules' => $result,
        'etapes'   => $etapes,
        'message'  => 'Offre mobile creee avec succes',
    ]);

} catch (Exception $e) {
    if (isset($conn) && $conn) {
        oci_rollback($conn);
        oci_close($conn);
    }
    sendResponse(500, ['success' => false, 'etapes' => $etapes, 'error' => $e->getMessage()]);
}
