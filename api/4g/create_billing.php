<?php
/**
 * POST /4g/create_billing.php
 *
 * Paramètre la facturation et le rating d'une ou plusieurs formules 4G.
 *
 * Pour chaque formule (spcode) :
 *   1. INSERT TARIFF_SERVICE_PACKAGE  — rattachement formule au rateplan
 *   2. INSERT MPULKTM1 × 6           — billing (le prix est porté par SNCODE 749)
 *   3. INSERT MPULKTM2 × 3           — rating (SNCODEs 749, 752, 755)
 *
 * Corps JSON requis :
 *   tmcode   (int)    — plan tarifaire
 *   vsdate   (string) — date de version dd-mm-yyyy (ex: "19-03-2024")
 *   formules (array)  — liste des formules :
 *     [
 *       { "spcode": 1826, "accessfee": 37190 },
 *       ...
 *     ]
 *     spcode    (int) — code formule retourné par create_formule.php
 *     accessfee (int) — prix en centimes, porté par SNCODE 749 dans MPULKTM1
 *
 * Réponse :
 *   {
 *     "success": true,
 *     "tmcode": 1256,
 *     "lignes_tsp":     1,
 *     "lignes_mpulktm1": 6,
 *     "lignes_mpulktm2": 3,
 *     "message": "Facturation de 1 formule(s) parametree(s) avec succes"
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
requireFields($body, ['tmcode', 'vsdate', 'formules']);

$tmcode   = (int) $body['tmcode'];
$vsdate   = trim($body['vsdate']);
$formules = $body['formules'];

if (!preg_match('/^\d{2}-\d{2}-\d{4}$/', $vsdate)) {
    sendResponse(400, ['success' => false, 'error' => 'Format vsdate invalide. Utilisez dd-mm-yyyy (ex: 19-03-2024)']);
}
if (!is_array($formules) || empty($formules)) {
    sendResponse(400, ['success' => false, 'error' => 'Le champ "formules" doit etre un tableau non vide']);
}
foreach ($formules as $i => $f) {
    if (!isset($f['spcode']) || !isset($f['accessfee'])) {
        sendResponse(400, ['success' => false,
            'error' => 'Champs "spcode" et "accessfee" requis dans formules[' . $i . ']']);
    }
}

// ── Données fixes MPULKTM2 — 3 lignes par formule ─────────────────────────────
// RATEIND, CP_PRICELIST_ID, FALLBACK_SRV_FLAG sont fixés
$tm2Rows = [
    ['sncode' => 752, 'svlcode' => 'GSMT**B*****SVO*', 'upcode' => 47, 'ricode' => 238, 'usage_type_id' => 2],
    ['sncode' => 755, 'svlcode' => 'GSMT**B*****SAF*', 'upcode' => 49, 'ricode' => 248, 'usage_type_id' => 2],
    ['sncode' => 749, 'svlcode' => 'GSMTWIB*****S***', 'upcode' => 47, 'ricode' => 238, 'usage_type_id' => 2],
];

// ── SQL MPULKTM1 ──────────────────────────────────────────────────────────────
// Champs fixes pour toutes les lignes :
//   SUBSCRIPT=0, ECHIND/AMTIND/FRQIND=null, SRVIND='P', PROIND='N',
//   ADVIND='A', SUSIND='N', CSIND=null, CLCODE=null,
//   ACCSERV='Ca1 20%'/'Cd1 20%'/'ALL', SUBSERV='Ca1 20%'/'Cd1 20%'/'ALL',
//   INTERVAL_TYPE='M', INTERVAL=1, PV_COMBI_ID=null,
//   DEF_PAYMENT_COND_ONETIME_REC=1, OVW_PERIOD_TYPE='M',
//   SUB_GL_ACC_PACK_ID=1, ACC_GL_ACC_PACK_ID=1, INITIAL_CREDIT=0
// Champs variants : SNCODE, ACCESSFEE, USGSERV, DEF_PAYMENT_COND_USG,
//   SERVICE_PRIORITY, DEF_TYPE_OF_CONTROL, USG_GL_ACC_PACK_ID
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

// ── SQL MPULKTM2 ──────────────────────────────────────────────────────────────
$sqlTm2 = "INSERT INTO MPULKTM2 (
                TMCODE, VSCODE, VSDATE, STATUS, SPCODE, SNCODE,
                SVLCODE, RATEIND, UPCODE, RICODE,
                REC_VERSION, USAGE_TYPE_ID, CP_PRICELIST_ID, FALLBACK_SRV_FLAG
            ) VALUES (
                :tmcode, 0, TO_DATE(:vsdate, 'DD-MM-YYYY'), 'W', :spcode, :sncode,
                :svlcode, 'Y', :upcode, :ricode,
                1, :usage_type_id, NULL, NULL
            )";

try {
    $conn = getConnection();

    $countTsp  = 0;
    $countTm1  = 0;
    $countTm2  = 0;

    foreach ($formules as $f) {
        $spcode    = (int) $f['spcode'];
        $accessfee = (int) $f['accessfee'];

        // ── TARIFF_SERVICE_PACKAGE ────────────────────────────────────────────
        $stmtTsp = oci_parse($conn,
            'INSERT INTO TARIFF_SERVICE_PACKAGE (TMCODE, SPCODE, TMVSCODE, SERVICE_PACKAGE_DEF, ATOMIC_SERVICE_PACKAGE)
             VALUES (:tmcode, :spcode, 0, NULL, NULL)');
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
        $countTsp++;

        // ── MPULKTM1 — 6 lignes ───────────────────────────────────────────────
        // SERVICE_PRIORITY = SNCODE pour chaque ligne
        $tm1Rows = [
            ['sncode' => 752, 'accessfee' => 0,          'usg_cat' => 'Ca1 20%', 'usg_code' => 'Cd1 20%', 'usg_type' => 'ALL', 'def_pay_usg' => 1, 'def_type_ctrl' => 2,    'usg_gl' => 1],
            ['sncode' => 751, 'accessfee' => 0,          'usg_cat' => null,       'usg_code' => null,       'usg_type' => null,  'def_pay_usg' => 3, 'def_type_ctrl' => null, 'usg_gl' => null],
            ['sncode' => 750, 'accessfee' => 0,          'usg_cat' => null,       'usg_code' => null,       'usg_type' => null,  'def_pay_usg' => 3, 'def_type_ctrl' => null, 'usg_gl' => null],
            ['sncode' => 755, 'accessfee' => 0,          'usg_cat' => 'Ca1 20%', 'usg_code' => 'Cd1 20%', 'usg_type' => 'ALL', 'def_pay_usg' => 1, 'def_type_ctrl' => 2,    'usg_gl' => 1],
            ['sncode' => 766, 'accessfee' => 0,          'usg_cat' => null,       'usg_code' => null,       'usg_type' => null,  'def_pay_usg' => 3, 'def_type_ctrl' => null, 'usg_gl' => null],
            ['sncode' => 749, 'accessfee' => $accessfee, 'usg_cat' => 'Ca1 20%', 'usg_code' => 'Cd1 20%', 'usg_type' => 'ALL', 'def_pay_usg' => 1, 'def_type_ctrl' => 2,    'usg_gl' => 1],
        ];

        foreach ($tm1Rows as $row) {
            $sncode       = $row['sncode'];
            $rowAccessfee = $row['accessfee'];
            $servicePrio  = $row['sncode'];

            $stmt = oci_parse($conn, $sqlTm1);
            if (!$stmt) {
                $err = oci_error($conn);
                throw new Exception('Erreur parse MPULKTM1 : ' . $err['message']);
            }
            oci_bind_by_name($stmt, ':tmcode',       $tmcode,               -1, SQLT_INT);
            oci_bind_by_name($stmt, ':vsdate',       $vsdate);
            oci_bind_by_name($stmt, ':spcode',       $spcode,               -1, SQLT_INT);
            oci_bind_by_name($stmt, ':sncode',       $sncode,               -1, SQLT_INT);
            oci_bind_by_name($stmt, ':accessfee',    $rowAccessfee,         -1, SQLT_INT);
            oci_bind_by_name($stmt, ':usg_cat',      $row['usg_cat'],       -1, SQLT_CHR);
            oci_bind_by_name($stmt, ':usg_code',     $row['usg_code'],      -1, SQLT_CHR);
            oci_bind_by_name($stmt, ':usg_type',     $row['usg_type'],      -1, SQLT_CHR);
            oci_bind_by_name($stmt, ':def_pay_usg',  $row['def_pay_usg'],   -1, SQLT_INT);
            oci_bind_by_name($stmt, ':service_prio', $servicePrio,          -1, SQLT_INT);
            oci_bind_by_name($stmt, ':def_type_ctrl',$row['def_type_ctrl'], -1, ociType($row['def_type_ctrl']));
            oci_bind_by_name($stmt, ':usg_gl',       $row['usg_gl'],        -1, ociType($row['usg_gl']));

            if (!oci_execute($stmt, OCI_NO_AUTO_COMMIT)) {
                $err = oci_error($stmt);
                oci_free_statement($stmt);
                throw new Exception('Erreur INSERT MPULKTM1 (SPCODE ' . $spcode . ', SNCODE ' . $sncode . ') : ' . $err['message']);
            }
            oci_free_statement($stmt);
            $countTm1++;
        }

        // ── MPULKTM2 — 3 lignes ───────────────────────────────────────────────
        foreach ($tm2Rows as $row) {
            $sncode      = $row['sncode'];
            $usageTypeId = $row['usage_type_id'];

            $stmt = oci_parse($conn, $sqlTm2);
            if (!$stmt) {
                $err = oci_error($conn);
                throw new Exception('Erreur parse MPULKTM2 : ' . $err['message']);
            }
            oci_bind_by_name($stmt, ':tmcode',        $tmcode,       -1, SQLT_INT);
            oci_bind_by_name($stmt, ':vsdate',        $vsdate);
            oci_bind_by_name($stmt, ':spcode',        $spcode,       -1, SQLT_INT);
            oci_bind_by_name($stmt, ':sncode',        $sncode,       -1, SQLT_INT);
            oci_bind_by_name($stmt, ':svlcode',       $row['svlcode']);
            oci_bind_by_name($stmt, ':upcode',        $row['upcode'], -1, SQLT_INT);
            oci_bind_by_name($stmt, ':ricode',        $row['ricode'], -1, SQLT_INT);
            oci_bind_by_name($stmt, ':usage_type_id', $usageTypeId,  -1, SQLT_INT);

            if (!oci_execute($stmt, OCI_NO_AUTO_COMMIT)) {
                $err = oci_error($stmt);
                oci_free_statement($stmt);
                throw new Exception('Erreur INSERT MPULKTM2 (SPCODE ' . $spcode . ', SNCODE ' . $sncode . ') : ' . $err['message']);
            }
            oci_free_statement($stmt);
            $countTm2++;
        }
    }

    oci_commit($conn);
    oci_close($conn);

    sendResponse(201, [
        'success'          => true,
        'tmcode'           => $tmcode,
        'lignes_tsp'       => $countTsp,
        'lignes_mpulktm1'  => $countTm1,
        'lignes_mpulktm2'  => $countTm2,
        'message'          => 'Facturation de ' . count($formules) . ' formule(s) parametree(s) avec succes',
    ]);

} catch (Exception $e) {
    if (isset($conn) && $conn) {
        oci_rollback($conn);
        oci_close($conn);
    }
    sendResponse(500, ['success' => false, 'error' => $e->getMessage()]);
}
