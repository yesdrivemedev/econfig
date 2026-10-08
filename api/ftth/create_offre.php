<?php
/**
 * POST /create_offre.php
 *
 * Création complète d'une offre FTTH de bout en bout en une seule transaction.
 *
 * Ordre des insertions :
 *   1. Vérification doublon SHDES
 *   2. RATEPLAN          (TMCODE auto)
 *   3. RATEPLAN_VERSION
 *   4. MPUPVTAB                  (PV_COMBI_ID auto)
 *   5. TARIFF_SERVICE_PACKAGE   (rattachement formule 1205 au rateplan)
 *   5b.[cesar] TARIFF_SERVICE_PACKAGE (formule 1532)
 *   6. MPULKTM1                 (6 lignes fixes)
 *   6b.[cesar] MPULKTM1         (SPCODE=1532, SNCODE=986)
 *   7. MPULKPV1                 (1 ligne par pricing)
 *   8. MPULKPV2                 (6 paramètres par pricing)
 *   8b.[cesar] MPULKPV1 + MPULKPV2 (pricing César, PARAMETER_ID=479)
 *   → COMMIT si tout réussit, ROLLBACK sur la moindre erreur
 *
 * Corps JSON requis :
 *   des              (string) — description longue (ex: "FTTR Confort")
 *   shdes            (string) — description courte unique (ex: "FTTRC")
 *   des_fr           (string) — segment marché (ex: "B2C", "B2B", "TEST")
 *   vsdate           (string) — date version dd-mm-yyyy (ex: "01-09-2025")
 *   apdate           (string) — date application dd-mm-yyyy hh:mm:ss (ex: "18-09-2025 10:24:32")
 *   extrnl_rateplan_id   (int)    — identifiant externe (ex: 1=postpaid, 2=prepaid)
 *   formule          (string) — intitulé commercial commun (ex: "FTTR CONFORT")  → param 448
 *   debit            (string) — débit montant (ex: "300M")                       → param 449
 *   debit_montant    (string) — débit upload (ex: "30M")                         → param 450
 *   debit_descendant (string) — débit descendant (ex: "300M")                    → param 451
 *   volume           (string) — volume inclus (ex: "4To")                        → param 452
 *   pricings         (array)  — liste des pricings (au moins un) :
 *     [
 *       { "set_id": 1, "des": "FTTR CONFORT", "accessfee": 33058, "duree": "Illimite" },
 *       { "set_id": 2, "des": "FTTR CONFORT 3M", "accessfee": 99000, "duree": "3 MOIS" },
 *       ...
 *     ]
 *     duree → param 453 (par pricing)
 *
 * Corps JSON optionnel :
 *   payment_method_ind   (string) — défaut : "P"
 *   charging_engine_code (int)    — défaut : 1
 *   cesar                (int)    — défaut : 0. Si 1, associe la formule César (SPCODE=1532).
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
 *     "pv_combi_id": 2981,
 *     "etapes": {
 *       "rateplan": "OK",
 *       "rateplan_version": "OK",
 *       "mpupvtab": "OK",
 *       "tariff_service_package": "OK",
 *       "mpulktm1": "OK (6 lignes)",
 *       "mpulkpv1": "OK (2 lignes)",
 *       "mpulkpv2": "OK (12 lignes)"
 *     },
 *     "message": "Offre creee avec succes"
 *   }
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/response.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(405, ['success' => false, 'error' => 'Methode non autorisee. Utilisez POST.']);
}

authenticate();

// ─────────────────────────────────────────────────────────────────────────────
// Lecture et validation des entrées
// ─────────────────────────────────────────────────────────────────────────────
$body = getRequestBody();
requireFields($body, [
    'des', 'shdes', 'des_fr', 'vsdate', 'apdate',
    'extrnl_rateplan_id', 'formule', 'debit', 'debit_montant', 'debit_descendant',
    'volume', 'pricings',
]);

$des             = trim($body['des']);
$shdes           = trim($body['shdes']);
$desFr           = trim($body['des_fr']);
$vsdate          = trim($body['vsdate']);
$apdate          = trim($body['apdate']);
$extrnlRateplanId   = (int) $body['extrnl_rateplan_id'];
$formule         = trim($body['formule']);
$debit           = trim($body['debit']);
$debitMontant    = trim($body['debit_montant']);
$debitDescendant = trim($body['debit_descendant']);
$volume          = trim($body['volume']);
$pricings        = $body['pricings'];

$paymentMethodInd   = isset($body['payment_method_ind'])   ? trim($body['payment_method_ind'])   : 'P';
$chargingEngineCode = isset($body['charging_engine_code'])  ? (int) $body['charging_engine_code']  : 1;
$cesar              = isset($body['cesar'])                  ? (int) $body['cesar']                  : 0;

if (!in_array($cesar, [0, 1])) {
    sendResponse(400, ['success' => false, 'error' => 'Le champ "cesar" doit etre 0 ou 1']);
}

$cesarDes = $cesarAccessfee = $cesarPrmValueString = null;
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

// Validation du tableau pricings
if (!is_array($pricings) || empty($pricings)) {
    sendResponse(400, ['success' => false, 'error' => 'Le champ "pricings" doit etre un tableau non vide']);
}
foreach ($pricings as $i => $p) {
    foreach (['set_id', 'des', 'accessfee', 'duree'] as $field) {
        if (!isset($p[$field]) || $p[$field] === '' || $p[$field] === null) {
            sendResponse(400, ['success' => false,
                'error' => 'Champ manquant dans pricings[' . $i . '] : ' . $field]);
        }
    }
}

if (!preg_match('/^\d{2}-\d{2}-\d{4}$/', $vsdate)) {
    sendResponse(400, ['success' => false, 'error' => 'Format vsdate invalide. Attendu : dd-mm-yyyy']);
}
if (!preg_match('/^\d{2}-\d{2}-\d{4} \d{2}:\d{2}:\d{2}$/', $apdate)) {
    sendResponse(400, ['success' => false, 'error' => 'Format apdate invalide. Attendu : dd-mm-yyyy hh:mm:ss']);
}

// ─────────────────────────────────────────────────────────────────────────────
// Données fixes MPULKTM1 — 6 lignes SNCODE invariables pour les offres FTTH
// ─────────────────────────────────────────────────────────────────────────────
// pv_combi_id sera injecté dans la ligne SNCODE 857 après génération
$mpulktm1Rows = [
    ['sncode' => 573, 'subscript' => null, 'accessfee_row' => null, 'event' => 0,    'echind' => 'A',  'amtind' => 'C',  'frqind' => 'P',  'proind' => 'N', 'accserv_catcode' => 'Ca2 20%', 'accserv_code' => 'Cd2 20%', 'pv_combi_id' => null, 'prm_print_ind' => 'N', 'def_payment_cond_usg' => 1, 'def_payment_cond_onetime_rec' => 1, 'ovw_period_type' => null, 'service_priority' => 1, 'csind' => 'X',  'clcode' => 2,    'initial_credit' => 0],
    ['sncode' => 574, 'subscript' => null, 'accessfee_row' => null, 'event' => 0,    'echind' => 'A',  'amtind' => 'C',  'frqind' => 'P',  'proind' => 'N', 'accserv_catcode' => 'Ca2 20%', 'accserv_code' => 'Cd2 20%', 'pv_combi_id' => null, 'prm_print_ind' => 'N', 'def_payment_cond_usg' => 1, 'def_payment_cond_onetime_rec' => 1, 'ovw_period_type' => null, 'service_priority' => 2, 'csind' => 'X',  'clcode' => 2,    'initial_credit' => 0],
    ['sncode' => 857, 'subscript' => 0,    'accessfee_row' => 0,    'event' => null, 'echind' => null, 'amtind' => null, 'frqind' => null, 'proind' => 'N', 'accserv_catcode' => 'Ca1 20%', 'accserv_code' => 'Cd1 20%', 'pv_combi_id' => null, 'prm_print_ind' => 'Y', 'def_payment_cond_usg' => 3, 'def_payment_cond_onetime_rec' => 1, 'ovw_period_type' => 'M',  'service_priority' => 3, 'csind' => 'X',  'clcode' => 2,    'initial_credit' => null],
    ['sncode' => 858, 'subscript' => 0,    'accessfee_row' => 0,    'event' => null, 'echind' => null, 'amtind' => null, 'frqind' => null, 'proind' => 'Y', 'accserv_catcode' => 'Ca2 20%', 'accserv_code' => 'Cd2 20%', 'pv_combi_id' => null, 'prm_print_ind' => 'N', 'def_payment_cond_usg' => 1, 'def_payment_cond_onetime_rec' => 1, 'ovw_period_type' => null, 'service_priority' => 3, 'csind' => 'X',  'clcode' => 2,    'initial_credit' => null],
    ['sncode' => 859, 'subscript' => 0,    'accessfee_row' => 0,    'event' => null, 'echind' => null, 'amtind' => null, 'frqind' => null, 'proind' => 'Y', 'accserv_catcode' => 'Ca1 20%', 'accserv_code' => 'Cd1 20%', 'pv_combi_id' => null, 'prm_print_ind' => 'N', 'def_payment_cond_usg' => 1, 'def_payment_cond_onetime_rec' => 1, 'ovw_period_type' => null, 'service_priority' => 3, 'csind' => 'X',  'clcode' => 2,    'initial_credit' => null],
    ['sncode' => 861, 'subscript' => 0,    'accessfee_row' => 0,    'event' => null, 'echind' => null, 'amtind' => null, 'frqind' => null, 'proind' => 'Y', 'accserv_catcode' => 'Ca2 20%', 'accserv_code' => 'Cd2 20%', 'pv_combi_id' => null, 'prm_print_ind' => 'N', 'def_payment_cond_usg' => 1, 'def_payment_cond_onetime_rec' => 1, 'ovw_period_type' => null, 'service_priority' => 3, 'csind' => null, 'clcode' => null, 'initial_credit' => null],
];


// ─────────────────────────────────────────────────────────────────────────────
// Exécution dans une transaction unique
// ─────────────────────────────────────────────────────────────────────────────
try {
    $conn  = getConnection();
    $etapes = [];

    // ── Étape 1 : vérification doublon SHDES ─────────────────────────────────
    $stmtChk = oci_parse($conn, 'SELECT COUNT(*) FROM RATEPLAN WHERE SHDES = :shdes');
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
        sendResponse(409, ['success' => false, 'error' => 'Un plan tarifaire avec ce SHDES existe deja : ' . $shdes]);
    }

    // ── Étape 2 : RATEPLAN ───────────────────────────────────────────────────
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

    $bindsRp = [
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
        )", $bindsRp);

    $etapes['rateplan'] = 'OK';

    // ── Étape 3 : RATEPLAN_VERSION ───────────────────────────────────────────
    $stmtRpv = oci_parse($conn,
        "INSERT INTO RATEPLAN_VERSION (
            VSCODE, VSDATE, STATUS, TMRC, APDATE, CURRENCY, REC_VERSION,
            TMCODE, PLCODE, IMPORT_EXPORT_FLAG,
            INT_SYNC, INVALID_IND, INVALIDATED_VSDATE, INVALIDATED_VSCODE,
            BACKDATE_IND, CORE_SERVICE_FLAG
        ) VALUES (
            0, TO_DATE(:vsdate, 'DD-MM-YYYY'), 'W', 0,
            TO_DATE(:apdate, 'DD-MM-YYYY HH24:MI:SS'),
            72, 1, :tmcode, NULL, 2,
            NULL, NULL, NULL, NULL, NULL, NULL
        )"
    );
    if (!$stmtRpv) {
        $err = oci_error($conn);
        throw new Exception('Erreur parse RATEPLAN_VERSION : ' . $err['message']);
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
    $etapes['rateplan_version'] = 'OK';

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

    // ── Étape 4 : MPUPVTAB ───────────────────────────────────────────────────
    $pvCombiId = (int) fetchScalar($conn, 'SELECT NVL(MAX(PV_COMBI_ID),0)+1 FROM MPUPVTAB');

    $stmtPvTab = oci_parse($conn, 'INSERT INTO MPUPVTAB (PV_COMBI_ID) VALUES (:pv_combi_id)');
    if (!$stmtPvTab) {
        $err = oci_error($conn);
        throw new Exception('Erreur parse MPUPVTAB : ' . $err['message']);
    }
    oci_bind_by_name($stmtPvTab, ':pv_combi_id', $pvCombiId, -1, SQLT_INT);
    if (!oci_execute($stmtPvTab, OCI_NO_AUTO_COMMIT)) {
        $err = oci_error($stmtPvTab);
        oci_free_statement($stmtPvTab);
        throw new Exception('Erreur INSERT MPUPVTAB : ' . $err['message']);
    }
    oci_free_statement($stmtPvTab);
    $etapes['mpupvtab'] = 'OK';

    // ── Étape 5 : TARIFF_SERVICE_PACKAGE — rattachement formule au rateplan ─────
    $stmtTsp = oci_parse($conn, 'INSERT INTO TARIFF_SERVICE_PACKAGE (TMCODE, SPCODE, TMVSCODE) VALUES (:tmcode, 1205, 0)');
    if (!$stmtTsp) {
        $err = oci_error($conn);
        throw new Exception('Erreur parse TARIFF_SERVICE_PACKAGE : ' . $err['message']);
    }
    oci_bind_by_name($stmtTsp, ':tmcode', $tmcode, -1, SQLT_INT);
    if (!oci_execute($stmtTsp, OCI_NO_AUTO_COMMIT)) {
        $err = oci_error($stmtTsp);
        oci_free_statement($stmtTsp);
        throw new Exception('Erreur INSERT TARIFF_SERVICE_PACKAGE : ' . $err['message']);
    }
    oci_free_statement($stmtTsp);
    $etapes['tariff_service_package'] = 'OK';

    // ── Étape 5b : MPUPVTAB + TARIFF_SERVICE_PACKAGE César (SPCODE=1532) ─────
    $cesarPvCombiId = null;
    if ($cesar === 1) {
        $cesarPvCombiId = (int) fetchScalar($conn, 'SELECT NVL(MAX(PV_COMBI_ID),0)+1 FROM MPUPVTAB');

        $stmtPvTabC = oci_parse($conn, 'INSERT INTO MPUPVTAB (PV_COMBI_ID) VALUES (:pv_combi_id)');
        if (!$stmtPvTabC) {
            $err = oci_error($conn);
            throw new Exception('Erreur parse MPUPVTAB cesar : ' . $err['message']);
        }
        oci_bind_by_name($stmtPvTabC, ':pv_combi_id', $cesarPvCombiId, -1, SQLT_INT);
        if (!oci_execute($stmtPvTabC, OCI_NO_AUTO_COMMIT)) {
            $err = oci_error($stmtPvTabC);
            oci_free_statement($stmtPvTabC);
            throw new Exception('Erreur INSERT MPUPVTAB cesar : ' . $err['message']);
        }
        oci_free_statement($stmtPvTabC);

        $stmtTspCesar = oci_parse($conn,
            'INSERT INTO TARIFF_SERVICE_PACKAGE (TMCODE, SPCODE, TMVSCODE) VALUES (:tmcode, 1532, 0)');
        if (!$stmtTspCesar) {
            $err = oci_error($conn);
            throw new Exception('Erreur parse TARIFF_SERVICE_PACKAGE cesar : ' . $err['message']);
        }
        oci_bind_by_name($stmtTspCesar, ':tmcode', $tmcode, -1, SQLT_INT);
        if (!oci_execute($stmtTspCesar, OCI_NO_AUTO_COMMIT)) {
            $err = oci_error($stmtTspCesar);
            oci_free_statement($stmtTspCesar);
            throw new Exception('Erreur INSERT TARIFF_SERVICE_PACKAGE cesar : ' . $err['message']);
        }
        oci_free_statement($stmtTspCesar);
        $etapes['tariff_service_package_cesar'] = 'OK';
    }

    // ── Étape 6 : MPULKTM1 (6 lignes) ────────────────────────────────────────
    // Injection du PV_COMBI_ID dans la ligne SNCODE 857
    $mpulktm1Rows[2]['pv_combi_id'] = $pvCombiId;

    $sqlTmb = "INSERT INTO MPULKTM1 (
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
                :tmcode, 0, TO_DATE(:vsdate, 'DD-MM-YYYY'), 'W', 1205, :sncode,
                :subscript, :accessfee_row, :event, :echind, :amtind, :frqind,
                'P', :proind, 'A', 'N',
                NULL, NULL, NULL, NULL,
                :csind, :clcode,
                :accserv_catcode, :accserv_code, 'ALL',
                NULL, NULL, NULL,
                'Ca1 20%', 'Cd1 20%', 'ALL',
                NULL, 'M', 1,
                :pv_combi_id, :prm_print_ind, 'N', 'N',
                1, NULL,
                :def_payment_cond_usg, NULL, :initial_credit,
                NULL, NULL,
                :def_payment_cond_onetime_rec,
                NULL, :ovw_period_type, NULL,
                :service_priority, 'B', NULL,
                NULL,
                1, 1, NULL,
                NULL,
                NULL, NULL, NULL
            )";

    foreach ($mpulktm1Rows as $row) {
        $stmtTmb = oci_parse($conn, $sqlTmb);
        if (!$stmtTmb) {
            $err = oci_error($conn);
            throw new Exception('Erreur parse MPULKTM1 : ' . $err['message']);
        }

        // NUMBER fixes
        oci_bind_by_name($stmtTmb, ':tmcode',                       $tmcode,                              -1, SQLT_INT);
        oci_bind_by_name($stmtTmb, ':sncode',                       $row['sncode'],                       -1, SQLT_INT);
        oci_bind_by_name($stmtTmb, ':def_payment_cond_usg',         $row['def_payment_cond_usg'],         -1, SQLT_INT);
        oci_bind_by_name($stmtTmb, ':def_payment_cond_onetime_rec', $row['def_payment_cond_onetime_rec'], -1, SQLT_INT);
        oci_bind_by_name($stmtTmb, ':service_priority',             $row['service_priority'],             -1, SQLT_INT);

        // NUMBER nullable
        oci_bind_by_name($stmtTmb, ':subscript',          $row['subscript'],          -1, ociType($row['subscript']));
        oci_bind_by_name($stmtTmb, ':accessfee_row',      $row['accessfee_row'],      -1, ociType($row['accessfee_row']));
        oci_bind_by_name($stmtTmb, ':event',              $row['event'],              -1, ociType($row['event']));
        oci_bind_by_name($stmtTmb, ':clcode',             $row['clcode'],             -1, ociType($row['clcode']));
        oci_bind_by_name($stmtTmb, ':pv_combi_id',        $row['pv_combi_id'],        -1, ociType($row['pv_combi_id']));
        oci_bind_by_name($stmtTmb, ':initial_credit',     $row['initial_credit'],     -1, ociType($row['initial_credit']));

        // VARCHAR/CHAR
        oci_bind_by_name($stmtTmb, ':vsdate',          $vsdate);
        oci_bind_by_name($stmtTmb, ':echind',          $row['echind'],          -1);
        oci_bind_by_name($stmtTmb, ':amtind',          $row['amtind'],          -1);
        oci_bind_by_name($stmtTmb, ':frqind',          $row['frqind'],          -1);
        oci_bind_by_name($stmtTmb, ':proind',          $row['proind']);
        oci_bind_by_name($stmtTmb, ':csind',           $row['csind'],           -1);
        oci_bind_by_name($stmtTmb, ':accserv_catcode', $row['accserv_catcode']);
        oci_bind_by_name($stmtTmb, ':accserv_code',    $row['accserv_code']);
        oci_bind_by_name($stmtTmb, ':prm_print_ind',   $row['prm_print_ind']);
        oci_bind_by_name($stmtTmb, ':ovw_period_type', $row['ovw_period_type'], -1);

        if (!oci_execute($stmtTmb, OCI_NO_AUTO_COMMIT)) {
            $err = oci_error($stmtTmb);
            oci_free_statement($stmtTmb);
            throw new Exception('Erreur INSERT MPULKTM1 (SNCODE ' . $row['sncode'] . ') : ' . $err['message']);
        }
        oci_free_statement($stmtTmb);
    }
    $etapes['mpulktm1'] = 'OK (6 lignes)';

    // ── Étape 6b : MPULKTM1 César (SPCODE=1532, SNCODE=986) ──────────────────
    if ($cesar === 1) {
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
        oci_bind_by_name($stmtTmbCesar, ':tmcode',      $tmcode,         -1, SQLT_INT);
        oci_bind_by_name($stmtTmbCesar, ':vsdate',      $vsdate);
        oci_bind_by_name($stmtTmbCesar, ':pv_combi_id', $cesarPvCombiId, -1, SQLT_INT);
        if (!oci_execute($stmtTmbCesar, OCI_NO_AUTO_COMMIT)) {
            $err = oci_error($stmtTmbCesar);
            oci_free_statement($stmtTmbCesar);
            throw new Exception('Erreur INSERT MPULKTM1 cesar : ' . $err['message']);
        }
        oci_free_statement($stmtTmbCesar);
        $etapes['mpulktm1_cesar'] = 'OK';
    }

    // ── Étapes 7 & 8 : MPULKPV1 + MPULKPV2 (une itération par pricing) ────────
    $sqlPvb = "INSERT INTO MPULKPV1 (
                   PV_COMBI_ID, VSCODE, SET_ID, DES, SUBSCRIPT, ACCESSFEE, REC_VERSION
               ) VALUES (
                   :pv_combi_id, 0, :set_id, :des, 0, :accessfee, 1
               )";

    $sqlPvm = "INSERT INTO MPULKPV2 (
                   PV_COMBI_ID, VSCODE, SET_ID, SCCODE,
                   PARAMETER_ID, LOWER_THRESHOLD,
                   PRM_VALUE_DATE, PRM_VALUE_NUMBER,
                   PRM_VALUE_STRING, REC_VERSION, PRM_VALUE_DES
               ) VALUES (
                   :pv_combi_id, 0, :set_id, 6,
                   :parameter_id, 1,
                   NULL, NULL,
                   :prm_value_string, 1, :prm_value_des
               )";

    $pvbCount = 0;
    $pvmCount = 0;

    foreach ($pricings as $pricing) {
        $setId     = (int)   $pricing['set_id'];
        $pvbDes    = trim($pricing['des']);
        $accessfee = (float) $pricing['accessfee'];
        $duree     = trim($pricing['duree']);

        // MPULKPVB
        $stmtPvb = oci_parse($conn, $sqlPvb);
        if (!$stmtPvb) {
            $err = oci_error($conn);
            throw new Exception('Erreur parse MPULKPV1 : ' . $err['message']);
        }
        oci_bind_by_name($stmtPvb, ':pv_combi_id', $pvCombiId, -1, SQLT_INT);
        oci_bind_by_name($stmtPvb, ':set_id',       $setId,     -1, SQLT_INT);
        oci_bind_by_name($stmtPvb, ':des',           $pvbDes);
        oci_bind_by_name($stmtPvb, ':accessfee',     $accessfee);
        if (!oci_execute($stmtPvb, OCI_NO_AUTO_COMMIT)) {
            $err = oci_error($stmtPvb);
            oci_free_statement($stmtPvb);
            throw new Exception('Erreur INSERT MPULKPV1 (SET_ID ' . $setId . ') : ' . $err['message']);
        }
        oci_free_statement($stmtPvb);
        $pvbCount++;

        // MPULKPVM — 6 paramètres
        // 448 : formule sans |1 ; 449-452 avec |1 ; 453 : duree sans |1
        $params = [
            ['id' => 448, 'str' => $formule,                     'des' => $formule],
            ['id' => 449, 'str' => $debit           . '|1',      'des' => $debit],
            ['id' => 450, 'str' => $debitMontant    . '|1',      'des' => $debitMontant],
            ['id' => 451, 'str' => $debitDescendant . '|1',      'des' => $debitDescendant],
            ['id' => 452, 'str' => $volume          . '|1',      'des' => $volume],
            ['id' => 453, 'str' => $duree,                       'des' => $duree],
        ];

        foreach ($params as $param) {
            $stmtPvm = oci_parse($conn, $sqlPvm);
            if (!$stmtPvm) {
                $err = oci_error($conn);
                throw new Exception('Erreur parse MPULKPV2 : ' . $err['message']);
            }
            oci_bind_by_name($stmtPvm, ':pv_combi_id',     $pvCombiId,   -1, SQLT_INT);
            oci_bind_by_name($stmtPvm, ':set_id',           $setId,       -1, SQLT_INT);
            oci_bind_by_name($stmtPvm, ':parameter_id',     $param['id'], -1, SQLT_INT);
            oci_bind_by_name($stmtPvm, ':prm_value_string', $param['str']);
            oci_bind_by_name($stmtPvm, ':prm_value_des',    $param['des']);
            if (!oci_execute($stmtPvm, OCI_NO_AUTO_COMMIT)) {
                $err = oci_error($stmtPvm);
                oci_free_statement($stmtPvm);
                throw new Exception('Erreur INSERT MPULKPV2 (SET_ID ' . $setId
                    . ', PARAMETER_ID ' . $param['id'] . ') : ' . $err['message']);
            }
            oci_free_statement($stmtPvm);
            $pvmCount++;
        }
    }

    $etapes['mpulkpv1'] = 'OK (' . $pvbCount . ' lignes)';
    $etapes['mpulkpv2'] = 'OK (' . $pvmCount . ' lignes)';

    // ── Étape 8b : MPULKPV1 + MPULKPV2 César ────────────────────────────────
    if ($cesar === 1) {
        $stmtPvbC = oci_parse($conn,
            "INSERT INTO MPULKPV1 (PV_COMBI_ID, VSCODE, SET_ID, DES, SUBSCRIPT, ACCESSFEE, REC_VERSION)
             VALUES (:pv_combi_id, 0, 1, :des, 0, :accessfee, 1)");
        if (!$stmtPvbC) {
            $err = oci_error($conn);
            throw new Exception('Erreur parse MPULKPV1 cesar : ' . $err['message']);
        }
        oci_bind_by_name($stmtPvbC, ':pv_combi_id', $cesarPvCombiId, -1, SQLT_INT);
        oci_bind_by_name($stmtPvbC, ':des',          $cesarDes);
        oci_bind_by_name($stmtPvbC, ':accessfee',    $cesarAccessfee);
        if (!oci_execute($stmtPvbC, OCI_NO_AUTO_COMMIT)) {
            $err = oci_error($stmtPvbC);
            oci_free_statement($stmtPvbC);
            throw new Exception('Erreur INSERT MPULKPV1 cesar : ' . $err['message']);
        }
        oci_free_statement($stmtPvbC);

        $stmtPvmC = oci_parse($conn,
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
        if (!$stmtPvmC) {
            $err = oci_error($conn);
            throw new Exception('Erreur parse MPULKPV2 cesar : ' . $err['message']);
        }
        oci_bind_by_name($stmtPvmC, ':pv_combi_id',      $cesarPvCombiId,     -1, SQLT_INT);
        oci_bind_by_name($stmtPvmC, ':prm_value_string', $cesarPrmValueString);
        oci_bind_by_name($stmtPvmC, ':prm_value_des',    $cesarDes);
        if (!oci_execute($stmtPvmC, OCI_NO_AUTO_COMMIT)) {
            $err = oci_error($stmtPvmC);
            oci_free_statement($stmtPvmC);
            throw new Exception('Erreur INSERT MPULKPV2 cesar : ' . $err['message']);
        }
        oci_free_statement($stmtPvmC);
        $etapes['mpulkpv1_cesar'] = 'OK';
        $etapes['mpulkpv2_cesar'] = 'OK';
    }

    // ── Commit global ─────────────────────────────────────────────────────────
    oci_commit($conn);
    oci_close($conn);

    $resp = [
        'success'     => true,
        'tmcode'      => $tmcode,
        'pv_combi_id' => $pvCombiId,
        'etapes'      => $etapes,
        'message'     => 'Offre creee avec succes',
    ];
    if ($cesarPvCombiId !== null) {
        $resp['cesar_pv_combi_id'] = $cesarPvCombiId;
    }
    sendResponse(201, $resp);

} catch (Exception $e) {
    if (isset($conn) && $conn) {
        oci_rollback($conn);
        oci_close($conn);
    }
    sendResponse(500, [
        'success' => false,
        'etapes'  => isset($etapes) ? $etapes : [],
        'error'   => $e->getMessage(),
    ]);
}
