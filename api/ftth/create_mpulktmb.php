<?php
/**
 * POST /create_mpulktmb.php
 *
 * Insère les 6 lignes fixes dans MPULKTM1 pour un plan tarifaire FTTH.
 * Le PV_COMBI_ID (utilisé pour SNCODE 857) est généré automatiquement via MAX()+1 depuis MPUPVTAB.
 *
 * Corps JSON requis :
 *   tmcode (int)    — TMCODE retourné par create_rateplan.php
 *   vsdate (string) — date au format dd-mm-yyyy (ex: "01-12-2024")
 *
 * Réponse :
 *   {
 *     "success": true,
 *     "tmcode": 1285,
 *     "pv_combi_id": 2981,
 *     "lignes_inserees": 6,
 *     "message": "Insertion MPULKTM1 effectuee avec succes"
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
requireFields($body, ['tmcode', 'vsdate']);

$tmcode = (int) $body['tmcode'];
$vsdate = trim($body['vsdate']);

// Validation du format de date dd-mm-yyyy
if (!preg_match('/^\d{2}-\d{2}-\d{4}$/', $vsdate)) {
    sendResponse(400, ['success' => false, 'error' => 'Format de date invalide. Utilisez dd-mm-yyyy (ex: 01-12-2024)']);
}

try {
    $conn = getConnection();

    // Génération automatique du PV_COMBI_ID depuis MPUPVTAB
    $pvCombiId = (int) fetchScalar($conn, 'SELECT NVL(MAX(PV_COMBI_ID),0)+1 FROM MPUPVTAB');

    // Insertion obligatoire dans MPUPVTAB avant toute utilisation du PV_COMBI_ID
    $stmtPvTab = oci_parse($conn, 'INSERT INTO MPUPVTAB (PV_COMBI_ID) VALUES (:pv_combi_id)');
    if (!$stmtPvTab) {
        $err = oci_error($conn);
        throw new Exception('Erreur parse SQL MPUPVTAB : ' . $err['message']);
    }
    oci_bind_by_name($stmtPvTab, ':pv_combi_id', $pvCombiId, -1, SQLT_INT);
    if (!oci_execute($stmtPvTab, OCI_NO_AUTO_COMMIT)) {
        $err = oci_error($stmtPvTab);
        oci_free_statement($stmtPvTab);
        throw new Exception('Erreur INSERT MPUPVTAB : ' . $err['message']);
    }
    oci_free_statement($stmtPvTab);

    /**
     * Les 6 lignes SNCODE fixes pour les offres FTTH.
     * Seuls TMCODE, VSDATE et PV_COMBI_ID (SNCODE 857) varient.
     * SUB_GL_ACC_PACK_ID et ACC_GL_ACC_PACK_ID sont hardcodés à 1 pour tous les SNCODEs.
     */
    $rows = [
        // SNCODE 573
        [
            'sncode'                      => 573,
            'subscript'                   => null,
            'accessfee'                   => null,
            'event'                       => 0,
            'echind'                      => 'A',
            'amtind'                      => 'C',
            'frqind'                      => 'P',
            'proind'                      => 'N',
            'accserv_catcode'             => 'Ca2 20%',
            'accserv_code'                => 'Cd2 20%',
            'pv_combi_id'                 => null,
            'prm_print_ind'               => 'N',
            'def_payment_cond_usg'        => 1,
            'def_payment_cond_onetime_rec' => 1,
            'ovw_period_type'             => null,
            'service_priority'            => 1,
            'csind'                       => 'X',
            'clcode'                      => 2,
            'initial_credit'              => 0,
        ],
        // SNCODE 574
        [
            'sncode'                      => 574,
            'subscript'                   => null,
            'accessfee'                   => null,
            'event'                       => 0,
            'echind'                      => 'A',
            'amtind'                      => 'C',
            'frqind'                      => 'P',
            'proind'                      => 'N',
            'accserv_catcode'             => 'Ca2 20%',
            'accserv_code'                => 'Cd2 20%',
            'pv_combi_id'                 => null,
            'prm_print_ind'               => 'N',
            'def_payment_cond_usg'        => 1,
            'def_payment_cond_onetime_rec' => 1,
            'ovw_period_type'             => null,
            'service_priority'            => 2,
            'csind'                       => 'X',
            'clcode'                       => 2,
            'initial_credit'              => 0,
        ],
        // SNCODE 857 — abonnement principal FTTH (avec PV_COMBI_ID)
        [
            'sncode'                      => 857,
            'subscript'                   => 0,
            'accessfee'                   => 0,
            'event'                       => null,
            'echind'                      => null,
            'amtind'                      => null,
            'frqind'                      => null,
            'proind'                      => 'N',
            'accserv_catcode'             => 'Ca1 20%',
            'accserv_code'                => 'Cd1 20%',
            'pv_combi_id'                 => $pvCombiId,
            'prm_print_ind'               => 'Y',
            'def_payment_cond_usg'        => 3,
            'def_payment_cond_onetime_rec' => 1,
            'ovw_period_type'             => 'M',
            'service_priority'            => 3,
            'csind'                       => 'X',
            'clcode'                       => 2,
            'initial_credit'              => null,
        ],
        // SNCODE 858
        [
            'sncode'                      => 858,
            'subscript'                   => 0,
            'accessfee'                   => 0,
            'event'                       => null,
            'echind'                      => null,
            'amtind'                      => null,
            'frqind'                      => null,
            'proind'                      => 'Y',
            'accserv_catcode'             => 'Ca2 20%',
            'accserv_code'                => 'Cd2 20%',
            'pv_combi_id'                 => null,
            'prm_print_ind'               => 'N',
            'def_payment_cond_usg'        => 1,
            'def_payment_cond_onetime_rec' => 1,
            'ovw_period_type'             => null,
            'service_priority'            => 3,
            'csind'                       => 'X',
            'clcode'                       => 2,
            'initial_credit'              => null,
        ],
        // SNCODE 859
        [
            'sncode'                      => 859,
            'subscript'                   => 0,
            'accessfee'                   => 0,
            'event'                       => null,
            'echind'                      => null,
            'amtind'                      => null,
            'frqind'                      => null,
            'proind'                      => 'Y',
            'accserv_catcode'             => 'Ca1 20%',
            'accserv_code'                => 'Cd1 20%',
            'pv_combi_id'                 => null,
            'prm_print_ind'               => 'N',
            'def_payment_cond_usg'        => 1,
            'def_payment_cond_onetime_rec' => 1,
            'ovw_period_type'             => null,
            'service_priority'            => 3,
            'csind'                       => 'X',
            'clcode'                       => 2,
            'initial_credit'              => null,
        ],
        // SNCODE 861 (sans CSIND/CLCODE)
        [
            'sncode'                      => 861,
            'subscript'                   => 0,
            'accessfee'                   => 0,
            'event'                       => null,
            'echind'                      => null,
            'amtind'                      => null,
            'frqind'                      => null,
            'proind'                      => 'Y',
            'accserv_catcode'             => 'Ca2 20%',
            'accserv_code'                => 'Cd2 20%',
            'pv_combi_id'                 => null,
            'prm_print_ind'               => 'N',
            'def_payment_cond_usg'        => 1,
            'def_payment_cond_onetime_rec' => 1,
            'ovw_period_type'             => null,
            'service_priority'            => 3,
            'csind'                       => null,
            'clcode'                       => null,
            'initial_credit'              => null,
        ],
    ];

    $sql = "INSERT INTO MPULKTM1 (
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
                :subscript, :accessfee, :event, :echind, :amtind, :frqind,
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

    // Rattachement de la formule au rateplan
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

    $insertedCount = 0;

    foreach ($rows as $row) {
        $stmt = oci_parse($conn, $sql);
        if (!$stmt) {
            $err = oci_error($conn);
            throw new Exception('Erreur parse SQL MPULKTM1 : ' . $err['message']);
        }

        // Colonnes NUMBER fixes — toujours SQLT_INT
        oci_bind_by_name($stmt, ':tmcode',                       $tmcode,                             -1, SQLT_INT);
        oci_bind_by_name($stmt, ':sncode',                       $row['sncode'],                      -1, SQLT_INT);
        oci_bind_by_name($stmt, ':def_payment_cond_usg',         $row['def_payment_cond_usg'],        -1, SQLT_INT);
        oci_bind_by_name($stmt, ':def_payment_cond_onetime_rec', $row['def_payment_cond_onetime_rec'],-1, SQLT_INT);
        oci_bind_by_name($stmt, ':service_priority',             $row['service_priority'],            -1, SQLT_INT);

        // Colonnes NUMBER nullable — ociType() choisit SQLT_INT si int, SQLT_CHR si null
        oci_bind_by_name($stmt, ':subscript',        $row['subscript'],        -1, ociType($row['subscript']));
        oci_bind_by_name($stmt, ':accessfee',        $row['accessfee'],        -1, ociType($row['accessfee']));
        oci_bind_by_name($stmt, ':event',            $row['event'],            -1, ociType($row['event']));
        oci_bind_by_name($stmt, ':clcode',           $row['clcode'],           -1, ociType($row['clcode']));
        oci_bind_by_name($stmt, ':pv_combi_id',      $row['pv_combi_id'],      -1, ociType($row['pv_combi_id']));
        oci_bind_by_name($stmt, ':initial_credit',   $row['initial_credit'],   -1, ociType($row['initial_credit']));

        // Colonnes VARCHAR/CHAR — SQLT_CHR (défaut)
        oci_bind_by_name($stmt, ':vsdate',           $vsdate);
        oci_bind_by_name($stmt, ':echind',           $row['echind'],           -1);
        oci_bind_by_name($stmt, ':amtind',           $row['amtind'],           -1);
        oci_bind_by_name($stmt, ':frqind',           $row['frqind'],           -1);
        oci_bind_by_name($stmt, ':proind',           $row['proind']);
        oci_bind_by_name($stmt, ':csind',            $row['csind'],            -1);
        oci_bind_by_name($stmt, ':accserv_catcode',  $row['accserv_catcode']);
        oci_bind_by_name($stmt, ':accserv_code',     $row['accserv_code']);
        oci_bind_by_name($stmt, ':prm_print_ind',    $row['prm_print_ind']);
        oci_bind_by_name($stmt, ':ovw_period_type',  $row['ovw_period_type'],  -1);

        if (!oci_execute($stmt, OCI_NO_AUTO_COMMIT)) {
            $err = oci_error($stmt);
            oci_free_statement($stmt);
            throw new Exception('Erreur INSERT MPULKTM1 (SNCODE ' . $row['sncode'] . ') : ' . $err['message']);
        }

        oci_free_statement($stmt);
        $insertedCount++;
    }

    oci_commit($conn);
    oci_close($conn);

    sendResponse(201, [
        'success'         => true,
        'tmcode'          => $tmcode,
        'pv_combi_id'     => $pvCombiId,
        'lignes_inserees' => $insertedCount,
        'message'         => 'Insertion MPULKTM1 effectuee avec succes',
    ]);

} catch (Exception $e) {
    if (isset($conn) && $conn) {
        oci_rollback($conn);
        oci_close($conn);
    }
    sendResponse(500, ['success' => false, 'error' => $e->getMessage()]);
}
