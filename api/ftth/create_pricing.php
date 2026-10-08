<?php
/**
 * POST /create_pricing.php
 *
 * Insère les données tarifaires dans MPULKPV1 (prix) et MPULKPV2 (paramètres).
 * Supporte plusieurs pricings (SET_ID différents) pour un même PV_COMBI_ID.
 *
 * Paramètres 448-452 communs à tous les pricings.
 * Paramètre 453 (durée) et les champs MPULKPV1 (des, accessfee, set_id) varient par pricing.
 *
 * Corps JSON requis :
 *   pv_combi_id      (int)    — PV_COMBI_ID retourné par create_mpulktmb.php
 *   formule          (string) — param 448, commun (ex: "FTTR CONFORT")
 *   debit            (string) — param 449, commun (ex: "300M")
 *   debit_montant    (string) — param 450, commun (ex: "30M")
 *   debit_descendant (string) — param 451, commun (ex: "300M")
 *   volume           (string) — param 452, commun (ex: "4To")
 *   pricings         (array)  — liste des pricings, au moins un élément :
 *     [
 *       { "set_id": 1, "des": "FTTR CONFORT", "accessfee": 33058,     "duree": "Illimite" },
 *       { "set_id": 2, "des": "FTTR CONFORT 3M", "accessfee": 99000,  "duree": "3 MOIS"  },
 *       ...
 *     ]
 *
 * Réponse :
 *   {
 *     "success": true,
 *     "pv_combi_id": 2981,
 *     "lignes_mpulkpvb": 2,
 *     "lignes_mpulkpvm": 12,
 *     "message": "Insertion pricing effectuee avec succes"
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
requireFields($body, ['pv_combi_id', 'formule', 'debit', 'debit_montant', 'debit_descendant', 'volume', 'pricings']);

$pvCombiId       = (int) $body['pv_combi_id'];
$formule         = trim($body['formule']);
$debit           = trim($body['debit']);
$debitMontant    = trim($body['debit_montant']);
$debitDescendant = trim($body['debit_descendant']);
$volume          = trim($body['volume']);
$pricings        = $body['pricings'];

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

try {
    $conn = getConnection();

    $pvbCount = 0;
    $pvmCount = 0;

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

    foreach ($pricings as $pricing) {
        $setId     = (int)   $pricing['set_id'];
        $des       = trim($pricing['des']);
        $accessfee = (float) $pricing['accessfee'];
        $duree     = trim($pricing['duree']);

        // ── MPULKPV1 ──────────────────────────────────────────────────────────
        $stmtPvb = oci_parse($conn, $sqlPvb);
        if (!$stmtPvb) {
            $err = oci_error($conn);
            throw new Exception('Erreur parse SQL MPULKPV1 : ' . $err['message']);
        }
        oci_bind_by_name($stmtPvb, ':pv_combi_id', $pvCombiId, -1, SQLT_INT);
        oci_bind_by_name($stmtPvb, ':set_id',       $setId,     -1, SQLT_INT);
        oci_bind_by_name($stmtPvb, ':des',           $des);
        oci_bind_by_name($stmtPvb, ':accessfee',     $accessfee);

        if (!oci_execute($stmtPvb, OCI_NO_AUTO_COMMIT)) {
            $err = oci_error($stmtPvb);
            oci_free_statement($stmtPvb);
            throw new Exception('Erreur INSERT MPULKPV1 (SET_ID ' . $setId . ') : ' . $err['message']);
        }
        oci_free_statement($stmtPvb);
        $pvbCount++;

        // ── MPULKPV2 — 6 paramètres ───────────────────────────────────────────
        // 448 : formule  — sans |1
        // 449 à 452      — avec |1
        // 453 : duree    — sans |1
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
                throw new Exception('Erreur parse SQL MPULKPV2 : ' . $err['message']);
            }
            oci_bind_by_name($stmtPvm, ':pv_combi_id',      $pvCombiId,       -1, SQLT_INT);
            oci_bind_by_name($stmtPvm, ':set_id',            $setId,           -1, SQLT_INT);
            oci_bind_by_name($stmtPvm, ':parameter_id',      $param['id'],     -1, SQLT_INT);
            oci_bind_by_name($stmtPvm, ':prm_value_string',  $param['str']);
            oci_bind_by_name($stmtPvm, ':prm_value_des',     $param['des']);

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

    oci_commit($conn);
    oci_close($conn);

    sendResponse(201, [
        'success'         => true,
        'pv_combi_id'     => $pvCombiId,
        'lignes_mpulkpvb' => $pvbCount,
        'lignes_mpulkpvm' => $pvmCount,
        'message'         => 'Insertion pricing effectuee avec succes',
    ]);

} catch (Exception $e) {
    if (isset($conn) && $conn) {
        oci_rollback($conn);
        oci_close($conn);
    }
    sendResponse(500, ['success' => false, 'error' => $e->getMessage()]);
}
