<?php
/**
 * POST /mobile/create_billing.php
 *
 * Paramètre la facturation d'une ou plusieurs formules mobiles.
 * Pour chaque formule (spcode) :
 *   - 15 lignes dans MPULKTM1 (un par SNCODE : 1,2,3,4,5,6,8,9,10,11,50,52,53,61,191)
 *   - 14 lignes dans MPULKTM2 (mêmes SNCODEs sauf 191)
 *
 * VSCODE=0, STATUS='W' — fixés.
 * SERVICE_PRIORITY = valeur du SNCODE dans chaque ligne.
 * ACCESSFEE = $accessfee uniquement sur SNCODE 191 ; 0 pour tous les autres.
 *
 * Corps JSON requis :
 *   tmcode   (int)    — plan tarifaire
 *   vsdate   (string) — date de version dd-mm-yyyy (ex: "27-12-2025")
 *   formules (array)  — liste des formules à facturer :
 *     [
 *       { "spcode": 1454, "accessfee": 2119 },
 *       ...
 *     ]
 *
 * Réponse :
 *   {
 *     "success": true,
 *     "tmcode": 861,
 *     "lignes_mpulktm1": 15,
 *     "lignes_mpulktm2": 14,
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
    sendResponse(400, ['success' => false, 'error' => 'Format vsdate invalide. Utilisez dd-mm-yyyy (ex: 27-12-2025)']);
}
if (!is_array($formules) || empty($formules)) {
    sendResponse(400, ['success' => false, 'error' => 'Le champ "formules" doit etre un tableau non vide']);
}
foreach ($formules as $i => $f) {
    if (!isset($f['spcode']) || $f['spcode'] === '' || $f['spcode'] === null) {
        sendResponse(400, ['success' => false, 'error' => 'Champ "spcode" manquant dans formules[' . $i . ']']);
    }
    if (!isset($f['accessfee']) || $f['accessfee'] === '' || $f['accessfee'] === null) {
        sendResponse(400, ['success' => false, 'error' => 'Champ "accessfee" manquant dans formules[' . $i . ']']);
    }
}

// ── Données fixes par SNCODE pour MPULKTM1 ───────────────────────────────────
// proind : 'N' pour SNCODEs 53, 61, 191 ; 'Y' pour tous les autres
// usgserv: false (null) pour SNCODE 191 ; true ('Ca1 20%'/'Cd1 20%'/'ALL') sinon
// Pour SNCODE 191 : accessfee = paramètre reçu, def_type_of_control = null, usg_gl = null
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

// ── Données fixes par SNCODE pour MPULKTM2 (SNCODE 191 absent) ───────────────
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

try {
    $conn = getConnection();

    $totalTm1 = 0;
    $totalTm2 = 0;

    foreach ($formules as $f) {
        $spcode    = (int)   $f['spcode'];
        $accessfee = (float) $f['accessfee'];

        // ── MPULKTM1 — 15 lignes ─────────────────────────────────────────────
        foreach ($tm1Defs as $sncode => $def) {
            $sncodeVal        = $sncode;
            $proindVal        = $def['proind'];
            $accessfeeRow     = ($sncode === 191) ? $accessfee : 0;
            $usgservCatcode   = $def['usgserv'] ? 'Ca1 20%' : null;
            $usgservCode      = $def['usgserv'] ? 'Cd1 20%' : null;
            $usgservType      = $def['usgserv'] ? 'ALL'      : null;
            $defTypeOfControl = ($sncode === 191) ? null : 2;
            $usgGlAccPackId   = ($sncode === 191) ? null : 1;
            $servicePriority  = $sncode;

            $stmt = oci_parse($conn, $sqlTm1);
            if (!$stmt) {
                $err = oci_error($conn);
                throw new Exception('Erreur parse MPULKTM1 : ' . $err['message']);
            }

            oci_bind_by_name($stmt, ':tmcode',            $tmcode,    -1, SQLT_INT);
            oci_bind_by_name($stmt, ':vsdate',            $vsdate);
            oci_bind_by_name($stmt, ':spcode',            $spcode,    -1, SQLT_INT);
            oci_bind_by_name($stmt, ':sncode',            $sncodeVal, -1, SQLT_INT);
            oci_bind_by_name($stmt, ':accessfee_row',     $accessfeeRow);
            oci_bind_by_name($stmt, ':proind',            $proindVal);
            oci_bind_by_name($stmt, ':usgserv_catcode',   $usgservCatcode,   -1);
            oci_bind_by_name($stmt, ':usgserv_code',      $usgservCode,      -1);
            oci_bind_by_name($stmt, ':usgserv_type',      $usgservType,      -1);
            oci_bind_by_name($stmt, ':service_priority',  $servicePriority,  -1, SQLT_INT);
            oci_bind_by_name($stmt, ':def_type_of_control', $defTypeOfControl, -1, ociType($defTypeOfControl));
            oci_bind_by_name($stmt, ':usg_gl_acc_pack_id',  $usgGlAccPackId,   -1, ociType($usgGlAccPackId));

            if (!oci_execute($stmt, OCI_NO_AUTO_COMMIT)) {
                $err = oci_error($stmt);
                oci_free_statement($stmt);
                throw new Exception('Erreur INSERT MPULKTM1 (SPCODE ' . $spcode
                    . ', SNCODE ' . $sncode . ') : ' . $err['message']);
            }
            oci_free_statement($stmt);
            $totalTm1++;
        }

        // ── MPULKTM2 — 14 lignes (SNCODE 191 absent) ─────────────────────────
        foreach ($tm2Defs as $sncode => $tm2) {
            $sncodeVal    = $sncode;
            $svlcodeVal   = $tm2['svlcode'];
            $usageTypeId  = $tm2['usage_type_id'];

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
            $totalTm2++;
        }
    }

    oci_commit($conn);
    oci_close($conn);

    sendResponse(201, [
        'success'          => true,
        'tmcode'           => $tmcode,
        'lignes_mpulktm1'  => $totalTm1,
        'lignes_mpulktm2'  => $totalTm2,
        'message'          => 'Facturation de ' . count($formules) . ' formule(s) parametree(s) avec succes',
    ]);

} catch (Exception $e) {
    if (isset($conn) && $conn) {
        oci_rollback($conn);
        oci_close($conn);
    }
    sendResponse(500, ['success' => false, 'error' => $e->getMessage()]);
}
